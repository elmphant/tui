<?php

declare(strict_types=1);

namespace Elmphant\Tui\Rendering;

use Elmphant\Tui\Layout\Rect;

final class Frame
{
    public readonly int $size;
    private readonly Rect $area;

    /**
     * Public for direct array access in the rendering hot loop (Screen, widgets).
     * Kept public intentionally — PHP method-call overhead per cell is too costly at 60 fps.
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
     * @var list<Rect>
     */
    private array $clipStack = [];

    /**
     * @var list<string>
     */
    private array $blankCharacters;
    /**
     * @var list<int>
     */
    private array $zeroCells;
    /**
     * @var list<int>
     */
    private array $blankWidths;
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
     * The region writes can currently land in.
     *
     * A widget taller than its viewport reads this to skip the rows that would
     * be discarded anyway: a 300-line table costs 300 writes per frame to draw
     * the 20 that show.
     */
    public function clip(): Rect
    {
        return $this->clipStack === [] ? $this->area : $this->clipStack[count($this->clipStack) - 1];
    }

    /**
     * Narrow the drawable region.
     *
     * Intersected with the clip already in force: a nested clip can only ever
     * shrink what is visible. Without that, a scroll container's child pushing
     * its own content-sized area would widen the region back out and undo the
     * container's clipping.
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
        if ($x < 0 || $x >= $this->width || $y < 0 || $y >= $this->height) {
            return;
        }
        if ($this->clipRejects($x, $y)) {
            return;
        }

        $characterWidth = CharWidth::graphemeWidth($character);
        if ($characterWidth > 1 && ($x + $characterWidth > $this->width || $this->clipRejects($x + 1, $y))) {
            return;
        }

        $rowBase = $y * $this->width;
        $this->repairOverwriteEdges($rowBase, $x, $x + $characterWidth);

        $index = $rowBase + $x;
        $this->characters[$index] = $character;
        $this->foregrounds[$index] = $foreground;
        $this->backgrounds[$index] = $background;
        $this->attributes[$index] = $attributes;
        $this->widths[$index] = $characterWidth;
        $this->markRowDirty($y);

        if ($characterWidth > 1) {
            $nextIndex = $index + 1;
            $this->characters[$nextIndex] = '';
            $this->foregrounds[$nextIndex] = $foreground;
            $this->backgrounds[$nextIndex] = $background;
            $this->attributes[$nextIndex] = $attributes;
            $this->widths[$nextIndex] = 0;
        }
    }

    /**
     * Write plain text. Returns columns consumed.
     */
    public function putString(int $x, int $y, string $text, int $foreground = 0, int $background = 0, int $attributes = 0): int
    {
        if ($y < 0 || $y >= $this->height) {
            return 0;
        }
        if ($this->clipStack !== []) {
            $top = $this->clipStack[count($this->clipStack) - 1];
            if ($y < $top->y || $y >= $top->bottom()) {
                return 0;
            }
        }

        // Fast path: pure printable ASCII skips grapheme extraction and CharWidth lookup.
        if (preg_match('/[^\x20-\x7e]/', $text) === 0) {
            if ($this->clipStack === []) {
                return $this->putAsciiString($x, $y, $text, $foreground, $background, $attributes);
            }
            $graphemes = str_split($text);
        } else {
            $text = TerminalText::plain($text);
            $graphemes = Grapheme::split($text);
        }

        $column = $x;
        $rowBase = $y * $this->width;
        $wroteAnything = false;

        foreach ($graphemes as $character) {
            if ($column >= $this->width) {
                break;
            }

            $characterWidth = CharWidth::graphemeWidth($character);
            if ($characterWidth === 0) {
                continue;
            }

            if ($characterWidth === 2 && $column + $characterWidth > $this->width) {
                $column += $characterWidth;
                continue;
            }

            if ($column >= 0 && !$this->clipRejects($column, $y)) {
                // A width-2 lead without its continuation makes Screen's diff draw the
                // glyph past the clip while the buffer still shows the old cell there.
                if ($characterWidth === 2 && $this->clipRejects($column + 1, $y)) {
                    $column += $characterWidth;
                    continue;
                }

                $this->repairOverwriteEdges($rowBase, $column, $column + $characterWidth);
                $index = $rowBase + $column;
                $this->characters[$index] = $character;
                $this->foregrounds[$index] = $foreground;
                $this->backgrounds[$index] = $background;
                $this->attributes[$index] = $attributes;
                $this->widths[$index] = $characterWidth;

                if ($characterWidth === 2) {
                    $nextIndex = $index + 1;
                    $this->characters[$nextIndex] = '';
                    $this->foregrounds[$nextIndex] = $foreground;
                    $this->backgrounds[$nextIndex] = $background;
                    $this->attributes[$nextIndex] = $attributes;
                    $this->widths[$nextIndex] = 0;
                }

                $wroteAnything = true;
            }

            $column += $characterWidth;
        }

        if ($wroteAnything) {
            $this->markRowDirty($y);
        }

        return $column - $x;
    }

    public function putStyled(int $x, int $y, string $text, Style $style): int
    {
        return $this->putString(
            $x,
            $y,
            $text,
            $style->foregroundValue(),
            $style->backgroundValue(),
            $style->attributes,
        );
    }

    /**
     * Draw a styled line, each span over $base, and return the width drawn.
     *
     * Spans carrying a StyleToken must be resolved first — `Line::withResolvedTokens()`
     * — because a frame has no theme to resolve them against.
     */
    public function putLine(int $x, int $y, Line $line, Style $base = new Style(), ?int $maxWidth = null): int
    {
        $limit = $x + ($maxWidth ?? $this->width - $x);
        $under = $line->style === null ? $base : self::spanStyleOver($line->style, $base);
        $start = $x;

        foreach ($line->spans as $span) {
            if ($x >= $limit) {
                break;
            }
            $content = CharWidth::truncateToWidth($span->content, $limit - $x);
            if ($content === '') {
                continue;
            }
            $x += $this->putStyled($x, $y, $content, self::spanStyleOver($span->style, $under));
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
        $previousIndex = null;

        while ($position < $length && $y < $this->height) {
            $byte = ord($text[$position]);

            if ($byte === 0x1B && $position + 1 < $length && $text[$position + 1] === '[') {
                $end = strpos($text, 'm', $position + 2);
                if ($end !== false) {
                    $parser->parse(substr($text, $position + 2, $end - $position - 2));
                    $position = $end + 1;
                    continue;
                }
                $scanPosition = $position + 2;
                while ($scanPosition < $length && ord($text[$scanPosition]) < 0x40) {
                    ++$scanPosition;
                }
                $position = min($scanPosition + 1, $length);
                continue;
            }

            if ($byte === 0x0A) {
                ++$y;
                $column = 0;
                ++$position;
                $previousIndex = null;
                continue;
            }

            if ($byte === 0x0D) {
                $column = 0;
                ++$position;
                $previousIndex = null;
                continue;
            }

            if ($byte === 0x09) {
                $tabStop = 8 - ($column % 8);
                for ($tabIndex = 0; $tabIndex < $tabStop && $column < $this->width; ++$tabIndex) {
                    $this->putChar($column, $y, ' ', $parser->foreground, $parser->background, $parser->attributes);
                    ++$column;
                }
                ++$position;
                $previousIndex = null;
                continue;
            }

            if ($byte < 0x20) {
                ++$position;
                continue;
            }

            $characterByteLength = Grapheme::sequenceLength($byte);
            $character = substr($text, $position, $characterByteLength);
            $position += $characterByteLength;

            $characterWidth = CharWidth::of($character);
            if ($characterWidth === 0) {
                if ($previousIndex !== null) {
                    $this->characters[$previousIndex] .= $character;
                }
                continue;
            }

            $previousIndex = null;
            if ($column >= $this->width) {
                continue;
            }
            if ($characterWidth === 2 && $column + $characterWidth > $this->width) {
                $column += $characterWidth;
                continue;
            }

            if ($column >= 0 && $y >= 0 && !$this->clipRejects($column, $y)) {
                if ($characterWidth === 2 && $this->clipRejects($column + 1, $y)) {
                    $column += $characterWidth;
                    continue;
                }

                $rowBase = $y * $this->width;
                $this->repairOverwriteEdges($rowBase, $column, $column + $characterWidth);
                $index = $rowBase + $column;
                $this->characters[$index] = $character;
                $this->foregrounds[$index] = $parser->foreground;
                $this->backgrounds[$index] = $parser->background;
                $this->attributes[$index] = $parser->attributes;
                $this->widths[$index] = $characterWidth;

                if ($characterWidth === 2) {
                    $nextIndex = $index + 1;
                    $this->characters[$nextIndex] = '';
                    $this->foregrounds[$nextIndex] = $parser->foreground;
                    $this->backgrounds[$nextIndex] = $parser->background;
                    $this->attributes[$nextIndex] = $parser->attributes;
                    $this->widths[$nextIndex] = 0;
                }

                $this->markRowDirty($y);
                $previousIndex = $index;
            }
            $column += $characterWidth;
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

    public function overlay(self $source, int $offsetX = 0, int $offsetY = 0): void
    {
        for ($sourceY = 0; $sourceY < $source->height; ++$sourceY) {
            $destinationY = $sourceY + $offsetY;
            if ($destinationY < 0 || $destinationY >= $this->height) {
                continue;
            }

            $sourceBase = $sourceY * $source->width;
            $destinationBase = $destinationY * $this->width;
            $changed = false;

            for ($sourceX = 0; $sourceX < $source->width; ++$sourceX) {
                $destinationX = $sourceX + $offsetX;
                if ($destinationX < 0 || $destinationX >= $this->width) {
                    continue;
                }

                $sourceIndex = $sourceBase + $sourceX;
                if (
                    $source->characters[$sourceIndex] === ' '
                    && $source->foregrounds[$sourceIndex] === 0
                    && $source->backgrounds[$sourceIndex] === 0
                    && $source->attributes[$sourceIndex] === 0
                ) {
                    continue;
                }

                if ($source->widths[$sourceIndex] === 2 && $destinationX + 1 >= $this->width) {
                    continue;
                }

                $this->repairOverwriteEdges($destinationBase, $destinationX, $destinationX + 1);
                $destinationIndex = $destinationBase + $destinationX;
                $this->characters[$destinationIndex] = $source->characters[$sourceIndex];
                $this->foregrounds[$destinationIndex] = $source->foregrounds[$sourceIndex];
                $this->backgrounds[$destinationIndex] = $source->backgrounds[$sourceIndex];
                $this->attributes[$destinationIndex] = $source->attributes[$sourceIndex];
                $this->widths[$destinationIndex] = $source->widths[$sourceIndex];
                $changed = true;
            }

            if ($changed) {
                $this->markRowDirty($destinationY);
            }
        }
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

    public function copyRowFrom(self $source, int $row): void
    {
        $base = $row * $this->width;
        for ($column = 0; $column < $this->width; ++$column) {
            $index = $base + $column;
            $this->characters[$index] = $source->characters[$index];
            $this->foregrounds[$index] = $source->foregrounds[$index];
            $this->backgrounds[$index] = $source->backgrounds[$index];
            $this->attributes[$index] = $source->attributes[$index];
            $this->widths[$index] = $source->widths[$index];
        }
        if (isset($source->contentRows[$row])) {
            $this->contentRows[$row] = true;
        } else {
            unset($this->contentRows[$row]);
        }
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
     * Mark a row changed after direct hot-loop array writes.
     *
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

    public function swapArrays(self $other): void
    {
        $temporaryCharacters = $this->characters;
        $temporaryForegrounds = $this->foregrounds;
        $temporaryBackgrounds = $this->backgrounds;
        $temporaryAttributes = $this->attributes;
        $temporaryWidths = $this->widths;
        $temporaryContentRows = $this->contentRows;
        $temporaryBlankBackground = $this->blankBackground;
        $temporaryBlankBackgrounds = $this->blankBackgrounds;

        $this->characters = $other->characters;
        $this->foregrounds = $other->foregrounds;
        $this->backgrounds = $other->backgrounds;
        $this->attributes = $other->attributes;
        $this->widths = $other->widths;
        $this->contentRows = $other->contentRows;
        $this->blankBackground = $other->blankBackground;
        $this->blankBackgrounds = $other->blankBackgrounds;

        $other->characters = $temporaryCharacters;
        $other->foregrounds = $temporaryForegrounds;
        $other->backgrounds = $temporaryBackgrounds;
        $other->attributes = $temporaryAttributes;
        $other->widths = $temporaryWidths;
        $other->contentRows = $temporaryContentRows;
        $other->blankBackground = $temporaryBlankBackground;
        $other->blankBackgrounds = $temporaryBlankBackgrounds;
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
     * Overwriting cells [startColumn, endColumn) may cut an existing wide character in half:
     * an orphaned lead renders over the new cells, an orphaned continuation ('') makes
     * Screen's run collector shift the rest of the row left. Style is kept, only character
     * and width become blank.
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

    private function putAsciiString(int $x, int $y, string $text, int $foreground, int $background, int $attributes): int
    {
        $rowBase = $y * $this->width;
        $length = strlen($text);
        $startByte = max(0, -$x);
        $writeColumn = max(0, $x);
        $end = min($this->width, $x + $length);

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
