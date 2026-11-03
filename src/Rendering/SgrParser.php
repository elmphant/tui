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

    public function parse(string $params): void
    {
        $parts = explode(';', $params);
        $partCount = count($parts);
        $index = 0;

        while ($index < $partCount) {
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
                $parameter === 38 || $parameter === 48 => $index = $this->parseExtendedColor($parts, $partCount, $index, $parameter === 48),
                default => null,
            };

            ++$index;
        }
    }

    /**
     * @param list<string> $parts
     */
    private function parseExtendedColor(array $parts, int $partCount, int $index, bool $isBackground): int
    {
        if ($index + 1 >= $partCount) {
            return $index;
        }

        $mode = (int)$parts[$index + 1];

        if ($mode === 5 && $index + 2 < $partCount) {
            $colorValue = Color::C256 + ((int)$parts[$index + 2] & 0xFF);
            if ($isBackground) {
                $this->background = $colorValue;
            } else {
                $this->foreground = $colorValue;
            }

            return $index + 2;
        }

        if ($mode === 2 && $index + 4 < $partCount) {
            $colorValue = Color::TRUE
                | (((int)$parts[$index + 2] & 0xFF) << 16) | (((int)$parts[$index + 3] & 0xFF) << 8) | ((int)$parts[$index + 4] & 0xFF);
            if ($isBackground) {
                $this->background = $colorValue;
            } else {
                $this->foreground = $colorValue;
            }

            return $index + 4;
        }

        return $index + 1;
    }
}
