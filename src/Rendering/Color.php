<?php

declare(strict_types=1);

namespace Elmphant\Tui\Rendering;

use Elmphant\Tui\Terminal\ColorProfile;
use InvalidArgumentException;

final readonly class Color
{
    public const ANSI = 0x01;
    public const BRIGHT = 0x10;
    public const C256 = 0x100;
    public const TRUE = 0x1000000;

    private const CUBE_LEVELS = [0, 95, 135, 175, 215, 255];

    /**
     * Luminance threshold where black text has more contrast than white.
     */
    private const CONTRAST_CROSSOVER = 0.179;

    private const READABILITY_STEPS = 32;

    private const NEUTRAL_CHROMA = 24;

    /**
     * Midway between the palette's normal channel (205) and its bright one (255).
     */
    private const BRIGHT_CHANNEL = 230;

    private const BRIGHT_OFFSET = 8;

    /**
     * Keep greys in palette order so ties choose the lower index.
     */
    private const ANSI16_NEUTRALS = [0 => 0, 7 => 229, 8 => 127, 15 => 255];

    private const ANSI16_PALETTE = [
        [0, 0, 0], [205, 0, 0], [0, 205, 0], [205, 205, 0],
        [0, 0, 238], [205, 0, 205], [0, 205, 205], [229, 229, 229],
        [127, 127, 127], [255, 0, 0], [0, 255, 0], [255, 255, 0],
        [92, 92, 255], [255, 0, 255], [0, 255, 255], [255, 255, 255],
    ];

    private function __construct(public int $value)
    {
    }

    public static function fromValue(int $value): self
    {
        return new self($value);
    }

    public static function none(): self
    {
        return new self(0);
    }

    public static function ansi(int $index): self
    {
        if ($index < 0 || $index > 15) {
            throw new InvalidArgumentException("ANSI color index must be 0-15, got {$index}");
        }

        if ($index >= 8) {
            return new self(self::BRIGHT + $index - 8);
        }

        return new self(self::ANSI + $index);
    }

    public static function color256(int $index): self
    {
        if ($index < 0 || $index > 255) {
            throw new InvalidArgumentException("A 256-colour index must be 0-255, got {$index}");
        }

        return new self(self::C256 + $index);
    }

    public static function rgb(int $red, int $green, int $blue): self
    {
        foreach (['red' => $red, 'green' => $green, 'blue' => $blue] as $channel => $level) {
            if ($level < 0 || $level > 255) {
                throw new InvalidArgumentException("The {$channel} channel must be 0-255, got {$level}");
            }
        }

        return new self(self::TRUE + ($red << 16) + ($green << 8) + $blue);
    }

    public static function hex(string $hex): self
    {
        $hex = ltrim($hex, '#');

        if (strlen($hex) === 3 && ctype_xdigit($hex)) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
            throw new InvalidArgumentException("A hex colour must be six hex digits, got \"{$hex}\"");
        }

        $value = (int)hexdec($hex);

        return self::rgb(($value >> 16) & 0xFF, ($value >> 8) & 0xFF, $value & 0xFF);
    }

    public static function named(string $name): self
    {
        return new self(match (strtolower($name)) {
            'black' => self::ANSI,
            'red' => self::ANSI + 1,
            'green' => self::ANSI + 2,
            'yellow' => self::ANSI + 3,
            'blue' => self::ANSI + 4,
            'magenta' => self::ANSI + 5,
            'cyan' => self::ANSI + 6,
            'white' => self::ANSI + 7,
            'gray', 'grey' => self::BRIGHT,
            default => throw new InvalidArgumentException("Unknown colour name \"{$name}\""),
        });
    }

    public function downsampleTo(ColorProfile $profile): self
    {
        return match ($profile) {
            ColorProfile::TrueColor => $this,
            ColorProfile::Ansi256 => $this->value >= self::TRUE
                ? self::color256(self::rgbTo256(...$this->channels()))
                : $this,
            ColorProfile::Ansi => $this->value >= self::C256
                ? self::ansi(self::rgbTo16(...$this->channels()))
                : $this,
            ColorProfile::Ascii => self::none(),
        };
    }

    /**
     * Choose black or white by contrast; preserve terminal-default text when the background colour is unknown.
     */
    public function contrastingText(): self
    {
        if ($this->value === 0) {
            return self::none();
        }

        return $this->relativeLuminance() > self::CONTRAST_CROSSOVER ? self::ansi(0) : self::ansi(15);
    }

    /**
     * Mix from this colour (0) to the other (1); leave terminal-default colours unchanged because their channels are unknown.
     */
    public function blend(self $other, float $amount): self
    {
        if ($this->value === 0 || $other->value === 0) {
            return $this;
        }

        $amount = max(0.0, min(1.0, $amount));
        [$red, $green, $blue] = $this->channels();
        [$otherRed, $otherGreen, $otherBlue] = $other->channels();

        return self::rgb(
            (int)round($red + ($otherRed - $red) * $amount),
            (int)round($green + ($otherGreen - $green) * $amount),
            (int)round($blue + ($otherBlue - $blue) * $amount),
        );
    }

    public function toForegroundSgr(): string
    {
        return $this->sgr(background: false);
    }

    public function toBackgroundSgr(): string
    {
        return $this->sgr(background: true);
    }

    /**
     * Adjust toward black or white until the requested contrast is reached, or return that extreme if it cannot be reached.
     */
    public function readableOn(self $background, float $minimumRatio = 4.5): self
    {
        if ($this->value === 0 || $background->value === 0 || $this->contrastRatio($background) >= $minimumRatio) {
            return $this;
        }

        $extreme = $background->contrastingText();
        $backgroundLuminance = $background->relativeLuminance();

        // Contrast increases monotonically after the minimum, so every step beyond the first match also meets the ratio.
        $low = 1;
        $high = self::READABILITY_STEPS;
        while ($low < $high) {
            $step = intdiv($low + $high, 2);
            $lifted = $this->blend($extreme, $step / self::READABILITY_STEPS);

            if (self::ratioOf($lifted->relativeLuminance(), $backgroundLuminance) >= $minimumRatio) {
                $high = $step;
            } else {
                $low = $step + 1;
            }
        }

        return $low === self::READABILITY_STEPS ? $extreme : $this->blend($extreme, $low / self::READABILITY_STEPS);
    }

    /**
     * WCAG contrast ranges from 1 to 21; the terminal default has unknown luminance.
     */
    public function contrastRatio(self $other): float
    {
        if ($this->value === 0 || $other->value === 0) {
            return 1.0;
        }

        return self::ratioOf($this->relativeLuminance(), $other->relativeLuminance());
    }

    public function relativeLuminance(): float
    {
        [$red, $green, $blue] = $this->channels();

        return 0.2126 * self::linearize($red) + 0.7152 * self::linearize($green) + 0.0722 * self::linearize($blue);
    }

    private function sgr(bool $background): string
    {
        $base = $background ? 40 : 30;
        $extended = $background ? 48 : 38;

        if ($this->value === 0) {
            return $background ? '49' : '39';
        }

        if ($this->value >= self::TRUE) {
            [$red, $green, $blue] = $this->channels();

            return "{$extended};2;{$red};{$green};{$blue}";
        }

        if ($this->value >= self::C256) {
            return $extended . ';5;' . ($this->value - self::C256);
        }

        if ($this->value >= self::BRIGHT) {
            return (string)($base + 60 + $this->value - self::BRIGHT);
        }

        return (string)($base + $this->value - self::ANSI);
    }

    private static function ratioOf(float $luminance, float $otherLuminance): float
    {
        return (max($luminance, $otherLuminance) + 0.05) / (min($luminance, $otherLuminance) + 0.05);
    }

    private static function linearize(int $channel): float
    {
        $ratio = $channel / 255;

        return $ratio <= 0.04045 ? $ratio / 12.92 : (($ratio + 0.055) / 1.055) ** 2.4;
    }

    /**
     * @return array{int, int, int}
     */
    private function channels(): array
    {
        if ($this->value >= self::TRUE) {
            return [($this->value >> 16) & 0xFF, ($this->value >> 8) & 0xFF, $this->value & 0xFF];
        }

        if ($this->value >= self::C256) {
            return self::index256ToRgb($this->value - self::C256);
        }

        if ($this->value >= self::BRIGHT) {
            return self::ANSI16_PALETTE[$this->value - self::BRIGHT + 8];
        }

        return self::ANSI16_PALETTE[$this->value - self::ANSI];
    }

    /**
     * @return array{int, int, int}
     */
    private static function index256ToRgb(int $index): array
    {
        if ($index < 16) {
            return self::ANSI16_PALETTE[$index];
        }

        if ($index >= 232) {
            $grayBase = 8;
            $grayLevel = $grayBase + 10 * ($index - 232);

            return [$grayLevel, $grayLevel, $grayLevel];
        }

        $cube = $index - 16;

        return [
            self::CUBE_LEVELS[intdiv($cube, 36)],
            self::CUBE_LEVELS[intdiv($cube, 6) % 6],
            self::CUBE_LEVELS[$cube % 6],
        ];
    }

    /**
     * Match hue before brightness; nearest RGB distance would turn soft colours grey in a 16-colour palette.
     */
    private static function rgbTo16(int $red, int $green, int $blue): int
    {
        $highest = max($red, $green, $blue);
        $chroma = $highest - min($red, $green, $blue);

        if ($chroma <= self::NEUTRAL_CHROMA) {
            return self::nearestNeutral(intdiv($red + $green + $blue, 3));
        }

        $hue = self::hueSector($red, $green, $blue, $highest, $chroma);

        return $highest > self::BRIGHT_CHANNEL ? $hue + self::BRIGHT_OFFSET : $hue;
    }

    private static function nearestNeutral(int $grey): int
    {
        $bestIndex = 0;
        $bestDistance = PHP_INT_MAX;
        foreach (self::ANSI16_NEUTRALS as $index => $level) {
            $distance = ($grey - $level) ** 2;
            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $bestIndex = $index;
            }
        }

        return $bestIndex;
    }

    private static function hueSector(int $red, int $green, int $blue, int $highest, int $chroma): int
    {
        $turns = match ($highest) {
            $red => fmod(($green - $blue) / $chroma + 6.0, 6.0),
            $green => ($blue - $red) / $chroma + 2.0,
            default => ($red - $green) / $chroma + 4.0,
        };
        $degrees = $turns * 60.0;

        return match (true) {
            $degrees < 30.0, $degrees >= 330.0 => 1,
            $degrees < 90.0 => 3,
            $degrees < 150.0 => 2,
            $degrees < 210.0 => 6,
            $degrees < 270.0 => 4,
            default => 5,
        };
    }

    private static function rgbTo256(int $red, int $green, int $blue): int
    {
        $cubeRedIndex = self::nearestCubeIndex($red);
        $cubeGreenIndex = self::nearestCubeIndex($green);
        $cubeBlueIndex = self::nearestCubeIndex($blue);
        $cubeDistance = self::squaredDistance(
            $red, $green, $blue,
            self::CUBE_LEVELS[$cubeRedIndex], self::CUBE_LEVELS[$cubeGreenIndex], self::CUBE_LEVELS[$cubeBlueIndex],
        );

        $average = intdiv($red + $green + $blue, 3);
        $grayIndex = min(23, intdiv($average - 8 + 5, 10));
        $grayLevel = 8 + 10 * $grayIndex;
        $grayDistance = self::squaredDistance($red, $green, $blue, $grayLevel, $grayLevel, $grayLevel);

        if ($grayDistance < $cubeDistance) {
            return 232 + $grayIndex;
        }

        return 16 + 36 * $cubeRedIndex + 6 * $cubeGreenIndex + $cubeBlueIndex;
    }

    private static function nearestCubeIndex(int $channel): int
    {
        $bestIndex = 0;
        $bestDistance = PHP_INT_MAX;
        foreach (self::CUBE_LEVELS as $index => $level) {
            $distance = ($channel - $level) * ($channel - $level);
            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $bestIndex = $index;
            }
        }

        return $bestIndex;
    }

    private static function squaredDistance(int $red, int $green, int $blue, int $otherRed, int $otherGreen, int $otherBlue): int
    {
        return ($red - $otherRed) * ($red - $otherRed)
            + ($green - $otherGreen) * ($green - $otherGreen)
            + ($blue - $otherBlue) * ($blue - $otherBlue);
    }
}
