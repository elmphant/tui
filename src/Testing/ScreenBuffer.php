<?php

declare(strict_types=1);

namespace Elmphant\Tui\Testing;

use Elmphant\Tui\Rendering\CharWidth;
use Elmphant\Tui\Rendering\CursorPosition;
use Elmphant\Tui\Rendering\CursorShape;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Grapheme;

final class ScreenBuffer
{
    /**
     * @var array<int, array<int, string>>
     */
    private array $cells = [];

    private int $cursorRow = 0;
    private int $cursorCol = 0;
    private CursorShape $cursorShape = CursorShape::BlinkingBlock;
    private bool $cursorVisible = true;

    public function __construct(string $ansiOutput, private readonly int $width, private readonly int $height)
    {
        // The grid is sparse: readers fall back to ' ' for unwritten cells.
        $this->processAnsi($ansiOutput);
    }

    public static function fromFrame(Frame $frame): self
    {
        $screen = new self('', $frame->width, $frame->height);

        for ($row = 0; $row < $frame->height; ++$row) {
            $offset = $row * $frame->width;
            for ($col = 0; $col < $frame->width; ++$col) {
                $screen->cells[$row][$col] = $frame->characters[$offset + $col];
            }
        }

        $cursor = $frame->cursorPosition;
        $screen->cursorVisible = $cursor !== null;
        if ($cursor !== null) {
            $screen->cursorRow = $cursor->row;
            $screen->cursorCol = $cursor->col;
            $screen->cursorShape = $cursor->shape;
        }

        return $screen;
    }

    public function getPlainText(): string
    {
        $lines = [];
        for ($r = 0; $r < $this->height; ++$r) {
            $lines[] = rtrim($this->getLine($r));
        }

        return implode("\n", $lines);
    }

    public function getCursorPosition(): CursorPosition
    {
        return new CursorPosition(
            $this->cursorRow,
            $this->cursorCol,
            CursorShape::BlinkingBlock,
        );
    }

    public function getCursorShape(): CursorShape
    {
        return $this->cursorShape;
    }

    public function isCursorVisible(): bool
    {
        return $this->cursorVisible;
    }

    public function getLine(int $row): string
    {
        $line = '';
        for ($c = 0; $c < $this->width; ++$c) {
            $line .= $this->cells[$row][$c] ?? ' ';
        }

        return $line;
    }

    public function getTextAt(int $row, int $col, int $length): string
    {
        $text = '';
        for ($c = $col; $c < $col + $length; ++$c) {
            $text .= $this->cells[$row][$c] ?? ' ';
        }

        return $text;
    }

    private function processAnsi(string $data): void
    {
        $length = strlen($data);
        $position = 0;

        while ($position < $length) {
            if (ord($data[$position]) === 0x1B && $position + 1 < $length && $data[$position + 1] === '[') {
                $position = $this->parseCsi($data, $position + 2, $length);
                continue;
            }

            if (ord($data[$position]) < 0x20) {
                ++$position;
                continue;
            }

            $start = $position;
            while ($position < $length && ord($data[$position]) >= 0x20) {
                ++$position;
            }
            $this->writeText(substr($data, $start, $position - $start));
        }
    }

    private function writeText(string $text): void
    {
        foreach (Grapheme::split($text) as $grapheme) {
            $cellWidth = CharWidth::graphemeWidth($grapheme);
            if ($cellWidth === 0) {
                continue;
            }

            // cursorRow can never reach height: CUP clamps and printing never wraps.
            if ($this->cursorCol + $cellWidth > $this->width) {
                continue;
            }

            $this->cells[$this->cursorRow][$this->cursorCol] = $grapheme;
            for ($continuation = 1; $continuation < $cellWidth; ++$continuation) {
                $this->cells[$this->cursorRow][$this->cursorCol + $continuation] = '';
            }
            $this->cursorCol += $cellWidth;
        }
    }

    private function parseCsi(string $data, int $position, int $length): int
    {
        $params = '';
        while ($position < $length && ord($data[$position]) >= 0x30 && ord($data[$position]) <= 0x3F) {
            $params .= $data[$position];
            ++$position;
        }

        $intermediates = '';
        while ($position < $length && ord($data[$position]) >= 0x20 && ord($data[$position]) <= 0x2F) {
            $intermediates .= $data[$position];
            ++$position;
        }

        if ($position >= $length) {
            return $position;
        }

        $final = $data[$position];
        ++$position;
        $parts = $params !== '' ? array_map(intval(...), explode(';', $params)) : [];

        if ($final === 'H' || $final === 'f') {
            $this->cursorRow = isset($parts[0])
                ? max(0, min($this->height - 1, $parts[0] - 1))
                : 0;
            $this->cursorCol = isset($parts[1])
                ? max(0, min($this->width - 1, $parts[1] - 1))
                : 0;
        } elseif ($final === 'C') {
            $this->cursorCol = min($this->width - 1, $this->cursorCol + max(1, $parts[0] ?? 1));
        } elseif ($final === 'D') {
            $this->cursorCol = max(0, $this->cursorCol - max(1, $parts[0] ?? 1));
        } elseif ($final === 'B') {
            $this->cursorRow = min($this->height - 1, $this->cursorRow + max(1, $parts[0] ?? 1));
        } elseif ($final === 'A') {
            $this->cursorRow = max(0, $this->cursorRow - max(1, $parts[0] ?? 1));
        } elseif ($final === 'K') {
            for ($c = $this->cursorCol; $c !== $this->width; ++$c) {
                $this->cells[$this->cursorRow][$c] = ' ';
            }
        } elseif ($final === 'q' && $intermediates === ' ') {
            $shape = CursorShape::tryFrom($parts[0] ?? 1);
            if ($shape !== null) {
                $this->cursorShape = $shape;
            }
        } elseif (($final === 'h' || $final === 'l') && $params === '?25') {
            $this->cursorVisible = $final === 'h';
        }

        return $position;
    }
}
