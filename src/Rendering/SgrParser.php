<?php

declare(strict_types=1);

namespace Elmphant\Tui\Rendering;

final class SgrParser
{
    public int $foreground = 0;
    public int $background = 0;
    public int $attributes = 0;

    public function reset(): void
    {
        $this->foreground = 0;
        $this->background = 0;
        $this->attributes = 0;
    }

    public function parse(string $parameters): void
    {
        $parts = explode(';', $parameters);
        $partCount = count($parts);
        $index = 0;

        while ($index < $partCount) {
            if (str_contains($parts[$index], ':')) {
                $this->parseSubparameters(explode(':', $parts[$index]));
                ++$index;
                continue;
            }

            $parameter = (int)$parts[$index];

            match (true) {
                $parameter === 0 => $this->reset(),
                isset(Attr::BY_SGR_CODE[$parameter]) => $this->attributes |= Attr::BY_SGR_CODE[$parameter],
                $parameter === 22 => $this->attributes &= ~(Attr::BOLD | Attr::FAINT),
                $parameter > 22 && isset(Attr::BY_SGR_CODE[$parameter - 20]) => $this->attributes &= ~Attr::BY_SGR_CODE[$parameter - 20],
                $parameter >= 30 && $parameter <= 37 => $this->foreground = Color::ANSI + $parameter - 30,
                $parameter === 39 => $this->foreground = 0,
                $parameter >= 40 && $parameter <= 47 => $this->background = Color::ANSI + $parameter - 40,
                $parameter === 49 => $this->background = 0,
                $parameter >= 90 && $parameter <= 97 => $this->foreground = Color::BRIGHT + $parameter - 90,
                $parameter >= 100 && $parameter <= 107 => $this->background = Color::BRIGHT + $parameter - 100,
                $parameter === 38 || $parameter === 48 || $parameter === 58 => $index = $this->parseExtendedColor($parts, $partCount, $index, $parameter),
                default => null,
            };

            ++$index;
        }
    }

    /**
     * @param non-empty-list<string> $subparameters
     */
    private function parseSubparameters(array $subparameters): void
    {
        $code = (int)$subparameters[0];

        if ($code === 4) {
            $this->attributes = (int)($subparameters[1] ?? '1') === 0
                ? $this->attributes & ~Attr::UNDERLINE
                : $this->attributes | Attr::UNDERLINE;

            return;
        }

        if ($code === 38 || $code === 48) {
            $mode = (int)($subparameters[1] ?? '');
            // T.416 puts a colour space id, often empty, before the channels. Many programs leave it out.
            $skipsColourSpace = $mode === 2 && (count($subparameters) >= 6 || ($subparameters[2] ?? '') === '');
            $color = self::color($mode, array_slice($subparameters, $skipsColourSpace ? 3 : 2));
            if ($color !== null) {
                $this->paint($color, $code === 48);
            }
        }
    }

    /**
     * @param list<string> $parts
     */
    private function parseExtendedColor(array $parts, int $partCount, int $index, int $code): int
    {
        if ($index + 1 >= $partCount) {
            return $index;
        }

        $mode = (int)$parts[$index + 1];
        $length = match (true) {
            $mode === 5 && $index + 2 < $partCount => 2,
            $mode === 2 && $index + 4 < $partCount => 4,
            default => 1,
        };

        $color = self::color($mode, array_slice($parts, $index + 2, $length - 1));
        if ($color !== null && $code !== 58) {
            $this->paint($color, $code === 48);
        }

        return $index + $length;
    }

    /**
     * @param list<string> $arguments a palette index for mode 5, the red, green and blue channels for mode 2
     */
    private static function color(int $mode, array $arguments): ?int
    {
        if ($mode === 5 && count($arguments) >= 1) {
            return Color::COLOR_256 + ((int)$arguments[0] & 0xFF);
        }

        if ($mode !== 2 || count($arguments) < 3) {
            return null;
        }

        [$red, $green, $blue] = $arguments;

        return Color::TRUE_COLOR | (((int)$red & 0xFF) << 16) | (((int)$green & 0xFF) << 8) | ((int)$blue & 0xFF);
    }

    private function paint(int $color, bool $isBackground): void
    {
        if ($isBackground) {
            $this->background = $color;
        } else {
            $this->foreground = $color;
        }
    }
}
