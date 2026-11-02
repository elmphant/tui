<?php

declare(strict_types=1);

namespace Elmphant\Tui\Rendering;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Terminal\CursorShape;
use Elmphant\Tui\Text\AnsiTokenizer;
use Elmphant\Tui\Text\CharWidth;
use Elmphant\Tui\Text\Grapheme;
use Elmphant\Tui\Text\TerminalText;

final class Frame
{
    private const CONTROL_BYTES = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\x1f";

    public readonly int $size;
    private readonly Rect $area;

    /**
     * Direct array access avoids a method call per cell in rendering loops.
     *
     * @internal
     *
     * @var array<int, string>
     */
    public array $characters;
    /** @internal
     * @var array<int, int> */
    public array $foregrounds;
    /** @internal
     * @var array<int, int> */
    public array $backgrounds;
    /** @internal
     * @var array<int, int> */
    public array $attributes;
    /** @internal
     * @var array<int, int> */
    public array $widths;
    /** @internal
     * @var array<int, true> */
    public array $dirtyRows = [];
    /**
     * @var array<int, true> rows written since the last clear
     */
    private array $contentRows = [];

    /**
     * @var array<int, string> rows a wrapped line goes on from, with what the wrap dropped
     */
    private array $continuations = [];

    /**
     * @var list<Rect>
     */
    private array $clipStack = [];

    /**
     * @var list<string>
     */
    private readonly array $blankCharacters;
    /**
     * @var list<int>
     */
    private readonly array $zeroCells;
    /**
     * @var list<int>
     */
    private readonly array $blankWidths;
    /**
     * @var list<int>
     */
    private array $blankBackgrounds;
    private int $blankBackground = 0;

    public ?CursorPosition $cursorPosition = null;

    public function __construct(
        public readonly int $width,
        public readonly int $height,
    ) {
        $this->size = $width * $height;
        $this->area = Rect::fromSize($width, $height);
        $this->zeroCells = array_fill(0, $this->size, 0);
        $this->blankCharacters = array_fill(0, $this->size, ' ');
        $this->blankWidths = array_fill(0, $this->size, 1);
        $this->blankBackgrounds = $this->zeroCells;
        $this->characters = $this->blankCharacters;
        $this->foregrounds = $this->zeroCells;
        $this->backgrounds = $this->zeroCells;
        $this->attributes = $this->zeroCells;
        $this->widths = $this->blankWidths;
    }

    public function width(int $index): int
    {
        return $this->widths[$index];
    }

    public function setCursorPosition(int $x, int $y, CursorShape $shape): void
    {
        if (!$this->clip()->contains($x, $y)) {
            return;
        }

        $this->cursorPosition = new CursorPosition($y, $x, $shape);
    }

    public function area(): Rect
    {
        return $this->area;
    }

    /**
     * The rows down to the lowest one written since the last clear.
     */
    public function drawnHeight(): int
    {
        return $this->contentRows === [] ? 0 : max(array_keys($this->contentRows)) + 1;
    }

    /**
     * Read the clip to skip off-screen rows instead of writing cells that will be discarded.
     */
    public function clip(): Rect
    {
        return $this->clipStack === [] ? $this->area : $this->clipStack[count($this->clipStack) - 1];
    }

    /**
     * Intersect with the existing clip so nested children cannot undo their parent's clipping.
     */
    public function pushClip(Rect $clip): void
    {
        $this->clipStack[] = $this->clipStack === []
            ? $clip
            : $clip->intersect($this->clipStack[count($this->clipStack) - 1]);
    }

    public function popClip(): void
    {
        array_pop($this->clipStack);
    }

    public function putChar(int $x, int $y, string $character, int $foreground = 0, int $background = 0, int $attributes = 0): void
    {
        if ($y < 0 || $y >= $this->height) {
            return;
        }

        $character = self::printableGlyph($character);
        $characterWidth = CharWidth::graphemeWidth($character);
        if ($characterWidth > 0 && $this->placeGrapheme($x, $y, $character, $characterWidth, $foreground, $background, $attributes)) {
            $this->markRowDirty($y);
        }
    }

    public function putString(int $x, int $y, string $text, int $foreground = 0, int $background = 0, int $attributes = 0): int
    {
        if ($y < 0 || $y >= $this->height) {
            return 0;
        }
        $clip = $this->clipStack === [] ? null : $this->clipStack[count($this->clipStack) - 1];
        if ($clip !== null && ($y < $clip->y || $y >= $clip->bottom())) {
            return 0;
        }

        if (preg_match('/[^\x20-\x7e]/', $text) === 0) {
            if ($clip === null) {
                return $this->putAsciiString($x, $y, $text, $foreground, $background, $attributes);
            }

            return $this->putClippedAsciiString($x, $y, $text, $clip, $foreground, $background, $attributes);
        }

        return $this->putGraphemes($x, $y, TerminalText::plain($text), $foreground, $background, $attributes);
    }

    public function putStyled(int $x, int $y, string $text, Style $style): int
    {
        return $this->putString(
            $x,
            $y,
            $text,
            $style->foregroundValue(),
            $style->backgroundValue(),
            $style->cellAttributes(),
        );
    }

    /**
     * Resolve StyleTokens with Line::withResolvedTokens() first; a Frame has no theme.
     */
    public function putLine(int $x, int $y, Line $line, Style $base = new Style(), ?int $maxWidth = null): int
    {
        $limit = $x + ($maxWidth ?? $this->width - $x);
        $under = $line->style === null ? $base : self::spanStyleOver($line->style, $base);
        $start = $x;

        foreach ($line->spans as $span) {
            $content = CharWidth::truncateToWidth($span->content, $limit - $x);
            if ($content !== '') {
                $x += $this->putStyled($x, $y, $content, self::spanStyleOver($span->style, $under));
            }
            if ($content !== $span->content) {
                break;
            }
        }

        return $x - $start;
    }

    /**
     * Write text with inline ANSI SGR sequences parsed into cell styles.
     * Handles multi-line (\n advances y). Returns final [x, y] position.
     *
     * @return array{int, int}
     */
    public function putAnsi(int $x, int $y, string $text, ?SgrParser $parser = null): array
    {
        $parser ??= new SgrParser();
        $column = $x;
        $length = strlen($text);
        $position = 0;

        while ($position < $length && $y < $this->height) {
            $byte = ord($text[$position]);

            if ($byte === 0x1B) {
                $end = AnsiTokenizer::sequenceEnd($text, $position);

                if (($text[$position + 1] ?? '') === '[' && $text[$end - 1] === 'm') {
                    $parser->parse(substr($text, $position + 2, $end - $position - 3));
                }
                $position = $end;
                continue;
            }

            if ($byte === 0x0A) {
                ++$y;
                $column = 0;
                ++$position;
                continue;
            }

            if ($byte === 0x0D) {
                $column = 0;
                ++$position;
                continue;
            }

            if ($byte === 0x09) {
                $tabStop = CharWidth::TAB_STOP - ($column % CharWidth::TAB_STOP);
                for ($tabIndex = 0; $tabIndex < $tabStop && $column < $this->width; ++$tabIndex) {
                    $this->putChar($column, $y, ' ', $parser->foreground, $parser->background, $parser->attributes);
                    ++$column;
                }
                ++$position;
                continue;
            }

            if ($byte < 0x20) {
                ++$position;
                continue;
            }

            $run = substr($text, $position, strcspn($text, self::CONTROL_BYTES, $position));
            $position += strlen($run);

            foreach (Grapheme::split(TerminalText::plain($run)) as $character) {
                $characterWidth = CharWidth::graphemeWidth($character);
                if ($characterWidth === 0 || $column >= $this->width) {
                    continue;
                }

                if ($y >= 0 && $this->placeGrapheme($column, $y, $character, $characterWidth, $parser->foreground, $parser->background, $parser->attributes)) {
                    $this->markRowDirty($y);
                }
                $column += $characterWidth;
            }
        }

        return [$column, $y];
    }

    public function fillRect(int $x, int $y, int $width, int $height, int $foreground = 0, int $background = 0, int $attributes = 0, string $character = ' '): void
    {
        $startX = max(0, $x);
        $startY = max(0, $y);
        $endX = min($this->width, $x + $width);
        $endY = min($this->height, $y + $height);

        if ($this->clipStack !== []) {
            $top = $this->clipStack[count($this->clipStack) - 1];
            $startX = max($startX, $top->x);
            $startY = max($startY, $top->y);
            $endX = min($endX, $top->right());
            $endY = min($endY, $top->bottom());
        }

        $character = self::printableGlyph($character);
        $characterWidth = CharWidth::graphemeWidth($character);
        if ($characterWidth === 0) {
            return;
        }

        for ($row = $startY; $row < $endY; ++$row) {
            $base = $row * $this->width;
            $this->repairOverwriteEdges($base, $startX, $endX);

            if ($characterWidth === 1) {
                for ($column = $startX; $column < $endX; ++$column) {
                    $index = $base + $column;
                    $this->characters[$index] = $character;
                    $this->foregrounds[$index] = $foreground;
                    $this->backgrounds[$index] = $background;
                    $this->attributes[$index] = $attributes;
                    $this->widths[$index] = 1;
                }
                $this->markRowDirty($row);
                continue;
            }

            $column = $startX;
            while ($column + $characterWidth <= $endX) {
                $index = $base + $column;
                $this->characters[$index] = $character;
                $this->foregrounds[$index] = $foreground;
                $this->backgrounds[$index] = $background;
                $this->attributes[$index] = $attributes;
                $this->widths[$index] = $characterWidth;

                for ($continuation = 1; $continuation < $characterWidth; ++$continuation) {
                    $continuationIndex = $index + $continuation;
                    $this->characters[$continuationIndex] = '';
                    $this->foregrounds[$continuationIndex] = $foreground;
                    $this->backgrounds[$continuationIndex] = $background;
                    $this->attributes[$continuationIndex] = $attributes;
                    $this->widths[$continuationIndex] = 0;
                }
                $column += $characterWidth;
            }

            while ($column < $endX) {
                $index = $base + $column;
                $this->characters[$index] = ' ';
                $this->foregrounds[$index] = $foreground;
                $this->backgrounds[$index] = $background;
                $this->attributes[$index] = $attributes;
                $this->widths[$index] = 1;
                ++$column;
            }
            $this->markRowDirty($row);
        }
    }

    /**
     * Flips the attribute, so a mark over a row that is already reversed still shows.
     */
    public function toggleAttribute(int $x, int $y, int $width, int $attribute): void
    {
        $clip = $this->clip();

        if ($y < $clip->y || $y >= $clip->bottom()) {
            return;
        }

        $base = $y * $this->width;
        for ($column = max($x, $clip->x); $column < min($x + $width, $clip->right()); ++$column) {
            $this->attributes[$base + $column] ^= $attribute;
        }

        $this->markRowDirty($y);
    }

    /**
     * Says the line drawn on this row goes on in the next one, so copied text can
     * join the two again with what wrapping dropped between them.
     */
    public function markContinued(int $y, string $dropped): void
    {
        if ($y >= 0 && $y < $this->height) {
            $this->continuations[$y] = $dropped;
        }
    }

    public function continuation(int $y): ?string
    {
        return $this->continuations[$y] ?? null;
    }

    public function character(int $index): string
    {
        return $this->characters[$index];
    }

    public function foreground(int $index): int
    {
        return $this->foregrounds[$index];
    }

    public function background(int $index): int
    {
        return $this->backgrounds[$index];
    }

    public function attribute(int $index): int
    {
        return $this->attributes[$index];
    }

    public function snapshotFrom(self $source): void
    {
        $this->characters = $source->characters;
        $this->foregrounds = $source->foregrounds;
        $this->backgrounds = $source->backgrounds;
        $this->attributes = $source->attributes;
        $this->widths = $source->widths;
        $this->contentRows = $source->contentRows;
        $this->blankBackground = $source->blankBackground;
        $this->blankBackgrounds = $source->blankBackgrounds;
    }

    public function clear(int $background = 0): void
    {
        $backgroundChanged = $this->blankBackground !== $background;
        $blankBackgrounds = $this->blankBackgrounds($background);
        $this->cursorPosition = null;
        $this->dirtyRows = $backgroundChanged
            ? $this->allRows()
            : $this->contentRows;

        $this->characters = $this->blankCharacters;
        $this->foregrounds = $this->zeroCells;
        $this->backgrounds = $blankBackgrounds;
        $this->attributes = $this->zeroCells;
        $this->widths = $this->blankWidths;
        $this->contentRows = [];
        $this->continuations = [];
    }

    public function isRowDirty(int $row): bool
    {
        return isset($this->dirtyRows[$row]);
    }

    public function clearDirty(): void
    {
        $this->dirtyRows = [];
    }

    /**
     * @internal prefer putChar(), putString(), or fillRect() outside renderers
     */
    public function markRowDirty(int $row): void
    {
        $this->dirtyRows[$row] = true;
        $this->contentRows[$row] = true;
    }

    public function markAllDirty(): void
    {
        for ($row = 0; $row < $this->height; ++$row) {
            $this->dirtyRows[$row] = true;
            $this->contentRows[$row] = true;
        }
    }

    /**
     * A cell holds one printable grapheme: well-formed UTF-8 can still carry an escape sequence to the terminal.
     */
    private static function printableGlyph(string $character): string
    {
        $printable = preg_match('/[\x00-\x1f\x7f]|\xc2[\x80-\x9f]/', $character) !== 1 && mb_check_encoding($character, 'UTF-8')
            ? $character
            : TerminalText::plain($character);

        return mb_strlen($printable, 'UTF-8') > 1 ? Grapheme::first($printable) : $printable;
    }

    private static function spanStyleOver(?Style $span, Style $base): Style
    {
        if ($span === null) {
            return $base;
        }

        return new Style(
            foreground: $span->foreground ?? $base->foreground,
            background: $span->background ?? $base->background,
            attributes: $base->attributes | $span->attributes,
            link: $span->link ?? $base->link,
        );
    }

    private function clipRejects(int $x, int $y): bool
    {
        if ($this->clipStack === []) {
            return false;
        }
        $top = $this->clipStack[count($this->clipStack) - 1];

        return $x < $top->x || $x >= $top->right() || $y < $top->y || $y >= $top->bottom();
    }

    /**
     * Clear both halves of any wide character cut by the write; preserve their styles.
     * An orphaned lead would overdraw new cells, and an orphaned continuation would shift the row.
     */
    private function repairOverwriteEdges(int $rowBase, int $startColumn, int $endColumn): void
    {
        if ($startColumn >= $endColumn) {
            return;
        }

        if ($startColumn > 0 && $this->widths[$rowBase + $startColumn] === 0) {
            $leadIndex = $rowBase + $startColumn - 1;
            $this->characters[$leadIndex] = ' ';
            $this->widths[$leadIndex] = 1;
        }

        if ($endColumn < $this->width && $this->widths[$rowBase + $endColumn] === 0) {
            $continuationIndex = $rowBase + $endColumn;
            $this->characters[$continuationIndex] = ' ';
            $this->widths[$continuationIndex] = 1;
        }
    }

    private function putGraphemes(int $x, int $y, string $text, int $foreground, int $background, int $attributes): int
    {
        $column = $x;
        $wroteAnything = false;

        foreach (Grapheme::split($text) as $character) {
            if ($column >= $this->width) {
                break;
            }

            $characterWidth = CharWidth::graphemeWidth($character);
            if ($characterWidth === 0) {
                continue;
            }

            $wroteAnything = $this->placeGrapheme($column, $y, $character, $characterWidth, $foreground, $background, $attributes) || $wroteAnything;
            $column += $characterWidth;
        }

        if ($wroteAnything) {
            $this->markRowDirty($y);
        }

        return $column - $x;
    }

    /**
     * A wide character needs both columns inside the clip, or the terminal and buffer would disagree.
     */
    private function placeGrapheme(int $column, int $y, string $character, int $characterWidth, int $foreground, int $background, int $attributes): bool
    {
        if ($column < 0 || $column >= $this->width || $this->clipRejects($column, $y)) {
            return false;
        }
        if ($characterWidth > 1 && ($column + $characterWidth > $this->width || $this->clipRejects($column + 1, $y))) {
            return false;
        }

        $rowBase = $y * $this->width;
        $this->repairOverwriteEdges($rowBase, $column, $column + $characterWidth);

        $index = $rowBase + $column;
        $this->characters[$index] = $character;
        $this->foregrounds[$index] = $foreground;
        $this->backgrounds[$index] = $background;
        $this->attributes[$index] = $attributes;
        $this->widths[$index] = $characterWidth;

        if ($characterWidth > 1) {
            $continuation = $index + 1;
            $this->characters[$continuation] = '';
            $this->foregrounds[$continuation] = $foreground;
            $this->backgrounds[$continuation] = $background;
            $this->attributes[$continuation] = $attributes;
            $this->widths[$continuation] = 0;
        }

        return true;
    }

    private function putAsciiString(int $x, int $y, string $text, int $foreground, int $background, int $attributes): int
    {
        $rowBase = $y * $this->width;
        $length = strlen($text);
        $startByte = max(0, -$x);
        $writeColumn = max(0, $x);
        $end = min($this->width, $x + $length);
        if ($writeColumn >= $end) {
            return max(0, $end - $x);
        }

        $this->repairOverwriteEdges($rowBase, $writeColumn, $end);

        for ($offset = $startByte; $writeColumn < $end; $offset++, $writeColumn++) {
            $index = $rowBase + $writeColumn;
            $this->characters[$index] = $text[$offset];
            $this->foregrounds[$index] = $foreground;
            $this->backgrounds[$index] = $background;
            $this->attributes[$index] = $attributes;
            $this->widths[$index] = 1;
        }

        $this->markRowDirty($y);

        return $end - $x;
    }

    private function putClippedAsciiString(int $x, int $y, string $text, Rect $clip, int $foreground, int $background, int $attributes): int
    {
        $length = strlen($text);
        $from = max($x, $clip->x);
        $to = min($x + $length, $clip->right(), $this->width);
        if ($from < $to) {
            $this->putAsciiString($from, $y, substr($text, $from - $x, $to - $from), $foreground, $background, $attributes);
        }

        return max(0, min($this->width, $x + $length) - $x);
    }

    /**
     * @return array<int, true>
     */
    private function allRows(): array
    {
        return array_fill(0, $this->height, true);
    }

    /**
     * @return list<int>
     */
    private function blankBackgrounds(int $background): array
    {
        if ($this->blankBackground !== $background) {
            $this->blankBackground = $background;
            $this->blankBackgrounds = array_fill(0, $this->size, $background);
        }

        return $this->blankBackgrounds;
    }
}
