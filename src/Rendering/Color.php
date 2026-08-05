<?php

declare(strict_types=1);

namespace Elmphant\Tui\Rendering;

use Elmphant\Tui\Input\ColorProfile;
use InvalidArgumentException;

final readonly class Color
{
    public const ANSI = 0x01;
    public const BRIGHT = 0x10;
    public const C256 = 0x100;
    public const TRUE = 0x1000000;

    private const CUBE_LEVELS = [0, 95, 135, 175, 215, 255];

    /**
     * The WCAG luminance where black text stops out-contrasting white.
     */
    private const CONTRAST_CROSSOVER = 0.179;

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
        return new self(self::C256 + $index);
    }

    public static function rgb(int $red, int $green, int $blue): self
    {
        return new self(self::TRUE + ($red << 16) + ($green << 8) + $blue);
    }

    public static function hex(string $hex): self
    {
        $hex = ltrim($hex, '#');
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
            default => 0,
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
            ColorProfile::Ascii => self::none(), // value 0 also lands here: none() === default, so no guard needed
        };
    }

    /**
     * Black or white, whichever reads better on this colour — and the terminal
     * default on the default, whose luminance is unknowable.
     */
    public function contrastingText(): self
    {
        if ($this->value === 0) {
            return self::none();
        }

        return $this->relativeLuminance() > self::CONTRAST_CROSSOVER ? self::ansi(0) : self::ansi(15);
    }

    public function toSgr(bool $background): string
    {
        $base = $background ? 40 : 30;
        $extended = $background ? 48 : 38;

        if ($this->value === 0) {
            return $background ? '49' : '39';
        }

        if ($this->value >= self::TRUE) {
            $rgb = $this->value - self::TRUE;

            return $extended . ';2;' . (($rgb >> 16) & 0xFF) . ';' . (($rgb >> 8) & 0xFF) . ';' . ($rgb & 0xFF);
        }

        if ($this->value >= self::C256) {
            return $extended . ';5;' . ($this->value - self::C256);
        }

        if ($this->value >= self::BRIGHT) {
            return (string)($base + 60 + $this->value - self::BRIGHT);
        }

        return (string)($base + $this->value - self::ANSI);
    }

    private function relativeLuminance(): float
    {
        [$red, $green, $blue] = $this->channels();

        return 0.2126 * self::linearize($red) + 0.7152 * self::linearize($green) + 0.0722 * self::linearize($blue);
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
            $rgb = $this->value - self::TRUE;

            return [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF];
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

    private static function rgbTo16(int $red, int $green, int $blue): int
    {
        $bestIndex = 0;
        $bestDistance = PHP_INT_MAX;
        foreach (self::ANSI16_PALETTE as $index => [$paletteRed, $paletteGreen, $paletteBlue]) {
            $distance = self::squaredDistance($red, $green, $blue, $paletteRed, $paletteGreen, $paletteBlue);
            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $bestIndex = $index;
            }
        }

        return $bestIndex;
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
