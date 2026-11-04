<?php

declare(strict_types=1);

namespace Elmphant\Tui\Testing;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Color;
use Elmphant\Tui\Rendering\CursorPosition;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Hyperlinks;
use Elmphant\Tui\Rendering\SgrParser;
use Elmphant\Tui\Rendering\Style;
use Elmphant\Tui\Terminal\CursorShape;
use Elmphant\Tui\Text\CharWidth;
use Elmphant\Tui\Text\Grapheme;
use LogicException;

final class ScreenBuffer
{
    /**
     * @var array<int, array<int, string>>
     */
    private array $cells = [];

    /**
     * @var array<int, array<int, array{int, int, int}>> foreground, background, attributes
     */
    private array $styles = [];

    private int $cursorRow = 0;
    private int $cursorCol = 0;
    private CursorShape $cursorShape = CursorShape::BlinkingBlock;
    private bool $cursorVisible = true;
    private readonly SgrParser $sgr;

    public function __construct(string $ansiOutput, private readonly int $width, private readonly int $height)
    {
        $this->sgr = new SgrParser();
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
                $screen->styles[$row][$col] = [
                    $frame->foregrounds[$offset + $col],
                    $frame->backgrounds[$offset + $col],
                    $frame->attributes[$offset + $col],
                ];
            }
        }

        $cursor = $frame->cursorPosition();
        $screen->cursorVisible = $cursor !== null;
        if ($cursor !== null) {
            $screen->cursorRow = $cursor->y;
            $screen->cursorCol = $cursor->x;
            $screen->cursorShape = $cursor->shape;
        }

        return $screen;
    }

    public function plainText(): string
    {
        $lines = [];
        for ($r = 0; $r < $this->height; ++$r) {
            $lines[] = rtrim($this->lineAt($r));
        }

        return implode("\n", $lines);
    }

    public function cursorPosition(): CursorPosition
    {
        return new CursorPosition(
            $this->cursorCol,
            $this->cursorRow,
            CursorShape::BlinkingBlock,
        );
    }

    public function cursorShape(): CursorShape
    {
        return $this->cursorShape;
    }

    public function isCursorVisible(): bool
    {
        return $this->cursorVisible;
    }

    public function lineAt(int $row): string
    {
        $line = '';
        for ($c = 0; $c < $this->width; ++$c) {
            $line .= $this->cells[$row][$c] ?? ' ';
        }

        return $line;
    }

    public function textAt(int $column, int $row, int $length): string
    {
        $text = '';
        for ($cell = $column; $cell < $column + $length; ++$cell) {
            $text .= $this->cells[$row][$cell] ?? ' ';
        }

        return $text;
    }

    /**
     * Match positions use display columns and row order; $nth = 1 means the second match.
     * Within an area, only the start of a match must lie inside it.
     *
     * @return array{row: int, column: int}|null
     */
    public function find(string $text, int $nth = 0, ?Rect $within = null): ?array
    {
        $area = ($within ?? Rect::fromSize($this->width, $this->height))->intersect(Rect::fromSize($this->width, $this->height));

        for ($row = $area->y; $row < $area->bottom(); ++$row) {
            for ($column = $area->x; $column < $area->right(); ++$column) {
                if ($this->startsAt($row, $column, $text) && $nth-- === 0) {
                    return ['row' => $row, 'column' => $column];
                }
            }
        }

        return null;
    }

    /**
     * @return array{row: int, column: int}
     */
    public function findOrFail(string $text, int $nth = 0, ?Rect $within = null): array
    {
        return $this->find($text, $nth, $within) ?? throw new LogicException(sprintf('"%s" is not on screen', $text));
    }

    public function rowOf(string $text, ?Rect $within = null): ?int
    {
        return $this->find($text, within: $within)['row'] ?? null;
    }

    public function styleAt(int $column, int $row): Style
    {
        [$foreground, $background, $attributes] = $this->styles[$row][$column] ?? [0, 0, 0];

        return new Style(
            $foreground === 0 ? null : Color::fromValue($foreground),
            $background === 0 ? null : Color::fromValue($background),
            $attributes & Hyperlinks::SGR_ATTRIBUTES,
            Hyperlinks::urlIn($attributes),
        );
    }

    private function startsAt(int $row, int $column, string $text): bool
    {
        if ($text === '' || ($this->cells[$row][$column] ?? ' ') === '') {
            return false;
        }

        $read = '';
        for ($c = $column; $c < $this->width && \strlen($read) < \strlen($text); ++$c) {
            $read .= $this->cells[$row][$c] ?? ' ';
        }

        return $read === $text;
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

            $style = [$this->sgr->foreground, $this->sgr->background, $this->sgr->attributes];
            $this->cells[$this->cursorRow][$this->cursorCol] = $grapheme;
            $this->styles[$this->cursorRow][$this->cursorCol] = $style;
            for ($continuation = 1; $continuation < $cellWidth; ++$continuation) {
                $this->cells[$this->cursorRow][$this->cursorCol + $continuation] = '';
                $this->styles[$this->cursorRow][$this->cursorCol + $continuation] = $style;
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

        if ($final === 'm') {
            $this->sgr->parse($params);
        } elseif ($final === 'H' || $final === 'f') {
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
