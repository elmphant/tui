<?php

declare(strict_types=1);

namespace Elmphant\Tui\Rendering;

final class SgrParser
{
    public int $foreground = 0;
    public int $background = 0;
    public int $attributes = 0;

    /**
     * The CSI sequence beginning at `ESC [`, read to its final byte. A sequence
     * ends at the first byte from 0x40 to 0x7E, and only 'm' makes it SGR —
     * looking for the 'm' itself instead swallows the text of anything else
     * (`ESC [2K` before "mid" would eat as far as that word's own m).
     *
     * @return array{int, ?string} where the text carries on, and the parameters
     *                             to parse when the sequence was SGR
     */
    public static function csiAt(string $text, int $position): array
    {
        $length = strlen($text);
        $scan = $position + 2;

        while ($scan < $length && ord($text[$scan]) < 0x40) {
            ++$scan;
        }

        if ($scan >= $length) {
            return [$length, null];
        }

        return [
            $scan + 1,
            $text[$scan] === 'm' ? substr($text, $position + 2, $scan - $position - 2) : null,
        ];
    }

    public function reset(): void
    {
        $this->foreground = 0;
        $this->background = 0;
        $this->attributes = 0;
    }

    public function parse(string $params): void
    {
        if ($params === '' || $params === '0') {
            $this->reset();

            return;
        }

        $parts = explode(';', $params);
        $partCount = count($parts);
        $index = 0;

        while ($index < $partCount) {
            $parameter = (int)$parts[$index];

            match (true) {
                $parameter === 0 => $this->reset(),
                $parameter === 1 => $this->attributes |= Attr::BOLD,
                $parameter === 2 => $this->attributes |= Attr::FAINT,
                $parameter === 3 => $this->attributes |= Attr::ITALIC,
                $parameter === 4 => $this->attributes |= Attr::UNDERLINE,
                $parameter === 5 => $this->attributes |= Attr::BLINK,
                $parameter === 7 => $this->attributes |= Attr::REVERSE,
                $parameter === 9 => $this->attributes |= Attr::STRIKETHROUGH,
                $parameter === 22 => $this->attributes &= ~(Attr::BOLD | Attr::FAINT),
                $parameter === 23 => $this->attributes &= ~Attr::ITALIC,
                $parameter === 24 => $this->attributes &= ~Attr::UNDERLINE,
                $parameter === 25 => $this->attributes &= ~Attr::BLINK,
                $parameter === 27 => $this->attributes &= ~Attr::REVERSE,
                $parameter === 29 => $this->attributes &= ~Attr::STRIKETHROUGH,
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
