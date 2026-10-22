<?php

declare(strict_types=1);

namespace Elmphant\Tui\Rendering;

use Elmphant\Tui\Terminal\Ansi;
use Elmphant\Tui\Terminal\ColorProfile;
use Elmphant\Tui\Terminal\CursorShape;
use Elmphant\Tui\Terminal\TerminalOutput;
use Throwable;

final class Screen
{
    /**
     * Blended and pulsing colours mint fresh values every frame, so the colour
     * caches have no natural bound: past this many entries they start over.
     */
    private const COLOR_CACHE_LIMIT = 4096;

    private ?Frame $front = null;
    private int $currentForeground = 0;
    private int $currentBackground = 0;
    private int $currentAttributes = 0;

    /**
     * @var array<int, int>
     */
    private array $downsampleCache = [];
    /**
     * @var array<int, string>
     */
    private array $foregroundSgrCache = [];
    /**
     * @var array<int, string>
     */
    private array $backgroundSgrCache = [];
    /**
     * @var array<int, string>
     */
    private array $attributeSgrCache = [];

    /**
     * Cursor state the terminal was last told about, so an unchanged frame
     * writes nothing at all. Null until the first frame asserts it.
     */
    private ?CursorPosition $lastCursor = null;

    private bool $cursorAsserted = false;
    private ?CursorShape $lastShape = null;
    private bool $shown = false;

    public function __construct(
        private readonly TerminalOutput $terminal,
        private readonly ColorProfile $colorProfile = ColorProfile::TrueColor,
    ) {
    }

    public function flush(Frame $backBuffer): void
    {
        $painted = $this->front === null || $this->front->width !== $backBuffer->width || $this->front->height !== $backBuffer->height
            ? $this->renderFull($backBuffer)
            : $this->renderDiff($backBuffer, $this->front);
        $cursor = $this->cursorChange($backBuffer);

        if ($painted !== '' || $cursor !== '') {
            try {
                $this->terminal->write(Ansi::SYNC_START . $painted . $cursor . Ansi::SYNC_END);
                $this->terminal->flush();
            } catch (Throwable $error) {
                $this->front = null;

                throw $error;
            }
        }

        $this->snapshotFrontBuffer($backBuffer);
        $backBuffer->clearDirty();
    }

    public function forceRedraw(Frame $backBuffer): void
    {
        $this->front = null;
        $this->flush($backBuffer);
    }

    /**
     * For when something else has written to the terminal: the next flush draws every cell.
     */
    public function forget(): void
    {
        $this->front = null;
        $this->lastShape = null;
    }

    private function cursorChange(Frame $backBuffer): string
    {
        $position = $backBuffer->cursorPosition;

        if ($this->cursorAsserted && $this->matchesLastCursor($position)) {
            return '';
        }

        $change = $position === null
            ? Ansi::HIDE_CURSOR
            : $this->shapeChangeTo($position->shape)
                . Ansi::cursorTo($position->row, $position->col)
                . ($this->shown ? '' : Ansi::SHOW_CURSOR);

        $this->shown = $position !== null;
        $this->lastCursor = $position;
        $this->cursorAsserted = true;

        return $change;
    }

    /**
     * Nothing when the caret already has this shape. Writing the sequence again
     * restarts the blink, so a caret beside anything that repaints — a spinner, a
     * clock, an arriving log line — would sit steady and never blink at all.
     */
    private function shapeChangeTo(CursorShape $shape): string
    {
        if ($this->lastShape === $shape) {
            return '';
        }

        $this->lastShape = $shape;

        return Ansi::cursorShape($shape);
    }

    private function matchesLastCursor(?CursorPosition $position): bool
    {
        if ($position === null || $this->lastCursor === null) {
            return $position === $this->lastCursor;
        }

        return $position->row === $this->lastCursor->row
            && $position->col === $this->lastCursor->col
            && $position->shape === $this->lastCursor->shape;
    }

    private function renderDiff(Frame $backBuffer, Frame $front): string
    {
        $parts = $this->collectDiff($backBuffer, $front);

        if ($parts === []) {
            return '';
        }

        $this->cursorAsserted = false;
        $this->resetCurrentStyle();

        return implode('', $parts) . Ansi::RESET_STYLE;
    }

    /**
     * The cell arrays are hoisted into locals and compared inline: the diff
     * touches every cell of a dirty row, and a method call per cell costs more
     * than the comparison itself. 2.35ms per 200x50 full repaint through calls,
     * 1.62ms through locals.
     *
     * @return list<string>
     */
    private function collectDiff(Frame $backBuffer, Frame $front): array
    {
        $width = $backBuffer->width;
        $backCharacters = $backBuffer->characters;
        $backForegrounds = $backBuffer->foregrounds;
        $backBackgrounds = $backBuffer->backgrounds;
        $backAttributes = $backBuffer->attributes;
        $backWidths = $backBuffer->widths;
        $frontCharacters = $front->characters;
        $frontForegrounds = $front->foregrounds;
        $frontBackgrounds = $front->backgrounds;
        $frontAttributes = $front->attributes;

        $parts = [];
        $cursorX = -1;
        $cursorY = -1;

        for ($row = 0; $row < $backBuffer->height; ++$row) {
            if (!$backBuffer->isRowDirty($row)) {
                continue;
            }

            $rowOffset = $row * $width;
            if (array_slice($backCharacters, $rowOffset, $width) === array_slice($frontCharacters, $rowOffset, $width)
                && array_slice($backForegrounds, $rowOffset, $width) === array_slice($frontForegrounds, $rowOffset, $width)
                && array_slice($backBackgrounds, $rowOffset, $width) === array_slice($frontBackgrounds, $rowOffset, $width)
                && array_slice($backAttributes, $rowOffset, $width) === array_slice($frontAttributes, $rowOffset, $width)) {
                continue;
            }

            $column = 0;
            $repaintsTheRest = false;

            while ($column < $width) {
                $index = $rowOffset + $column;

                if (!$repaintsTheRest
                    && $backCharacters[$index] === $frontCharacters[$index]
                    && $backForegrounds[$index] === $frontForegrounds[$index]
                    && $backBackgrounds[$index] === $frontBackgrounds[$index]
                    && $backAttributes[$index] === $frontAttributes[$index]) {
                    $column += max(1, $backWidths[$index]);
                    continue;
                }

                if ($cursorX !== $column || $cursorY !== $row) {
                    $parts[] = self::moveCursor($column, $row, $cursorX, $cursorY);
                    $cursorY = $row;
                }

                $foreground = $backForegrounds[$index];
                $background = $backBackgrounds[$index];
                $attributes = $backAttributes[$index];

                $parts[] = $this->styleDelta($foreground, $background, $attributes);

                $runCharacters = [self::cellText($backCharacters[$index], $backWidths[$index], $row, $column)];
                $repaintsTheRest = $repaintsTheRest || self::isCluster($backCharacters[$index], $backWidths[$index]);
                $nextColumn = $column + max(1, $backWidths[$index]);

                while ($nextColumn < $width) {
                    $nextIndex = $rowOffset + $nextColumn;

                    if (!$repaintsTheRest
                        && $backCharacters[$nextIndex] === $frontCharacters[$nextIndex]
                        && $backForegrounds[$nextIndex] === $frontForegrounds[$nextIndex]
                        && $backBackgrounds[$nextIndex] === $frontBackgrounds[$nextIndex]
                        && $backAttributes[$nextIndex] === $frontAttributes[$nextIndex]) {
                        break;
                    }

                    if ($backForegrounds[$nextIndex] !== $foreground
                        || $backBackgrounds[$nextIndex] !== $background
                        || $backAttributes[$nextIndex] !== $attributes) {
                        break;
                    }

                    $nextCharacter = $backCharacters[$nextIndex];
                    $runCharacters[] = self::cellText($nextCharacter, $backWidths[$nextIndex], $row, $nextColumn);
                    $repaintsTheRest = $repaintsTheRest || self::isCluster($nextCharacter, $backWidths[$nextIndex]);
                    $nextColumn += max(1, $backWidths[$nextIndex]);
                }

                $parts[] = implode('', $runCharacters);
                $cursorX = $nextColumn;
                $column = $nextColumn;
            }
        }

        return $parts;
    }

    private function renderFull(Frame $backBuffer): string
    {
        $width = $backBuffer->width;
        $output = [];

        for ($row = 0; $row < $backBuffer->height; ++$row) {
            // Every cell is written anyway, but a terminal that reflowed the old screen
            // on a resize can keep a wide character's remnant under a narrow one (tmux).
            if ($row === 0) {
                $output[] = Ansi::HIDE_CURSOR . Ansi::CURSOR_HOME . Ansi::RESET_STYLE . Ansi::ERASE_TO_LINE_END;
            } else {
                $output[] = Ansi::cursorToRowStart($row) . Ansi::ERASE_TO_LINE_END;
            }

            $rowOffset = $row * $width;
            $column = 0;

            while ($column < $width) {
                $index = $rowOffset + $column;
                $foreground = $backBuffer->foregrounds[$index];
                $background = $backBuffer->backgrounds[$index];
                $attributes = $backBuffer->attributes[$index];

                $output[] = $this->styleDelta($foreground, $background, $attributes);
                [$runCharacters, $nextColumn] = $this->collectStyledRun($backBuffer, $rowOffset, $column, $width, $foreground, $background, $attributes);
                $output[] = $runCharacters;
                $column = $nextColumn;
            }
        }

        $output[] = Ansi::RESET_STYLE;
        $this->resetCurrentStyle();
        $this->shown = false;
        $this->cursorAsserted = false;

        return implode('', $output);
    }

    /**
     * Collect consecutive characters with the same style (for full redraw, no front buffer check).
     *
     * @return array{string, int} [characters, nextColumn]
     */
    private function collectStyledRun(Frame $frame, int $rowOffset, int $startColumn, int $width, int $foreground, int $background, int $attributes): array
    {
        $row = intdiv($rowOffset, $width);
        $parts = [self::cellText($frame->characters[$rowOffset + $startColumn], $frame->widths[$rowOffset + $startColumn], $row, $startColumn)];
        $nextColumn = $startColumn + max(1, $frame->widths[$rowOffset + $startColumn]);

        while ($nextColumn < $width) {
            $nextIndex = $rowOffset + $nextColumn;
            $nextCharacter = $frame->characters[$nextIndex];

            if (!$this->cellHasStyle($frame, $nextIndex, $foreground, $background, $attributes)) {
                break;
            }

            $parts[] = self::cellText($nextCharacter, $frame->widths[$nextIndex], $row, $nextColumn);
            $nextColumn += max(1, $frame->widths[$nextIndex]);
        }

        return [implode('', $parts), $nextColumn];
    }

    /**
     * A terminal draws a base character narrow and widens it only when U+FE0F
     * arrives; tmux drops the emoji when the cell it widens into still holds part
     * of an old wide character. Two spaces first clear both cells.
     */
    private static function cellText(string $character, int $width, int $row, int $column): string
    {
        if (!self::isCluster($character, $width)) {
            return $character;
        }

        // Back by absolute position: after a space in the last column the cursor waits to wrap, and a relative move would land one short.
        $text = str_contains($character, "\u{FE0F}") ? '  ' . Ansi::cursorTo($row, $column) . $character : $character;

        return $text . Ansi::cursorTo($row, $column + 2);
    }

    /**
     * A wide cluster of several code points, which terminals disagree on: tmux
     * 3.5a advances four columns for 👩‍👩‍👧‍👦. Placing the cursor after it keeps
     * the cells behind it in their columns, and the rest of its row is redrawn
     * over whatever it spilled into.
     */
    private static function isCluster(string $character, int $width): bool
    {
        return $width === 2 && strlen($character) > 4;
    }

    private function cellHasStyle(Frame $frame, int $index, int $foreground, int $background, int $attributes): bool
    {
        return $frame->foregrounds[$index] === $foreground
            && $frame->backgrounds[$index] === $background
            && $frame->attributes[$index] === $attributes;
    }

    private function resetCurrentStyle(): void
    {
        $this->currentForeground = 0;
        $this->currentBackground = 0;
        $this->currentAttributes = 0;
    }

    private function snapshotFrontBuffer(Frame $source): void
    {
        if ($this->front === null || $this->front->width !== $source->width || $this->front->height !== $source->height) {
            $this->front = new Frame($source->width, $source->height);
        }
        $this->front->snapshotFrom($source);
    }

    private function styleDelta(int $foreground, int $background, int $attributes): string
    {
        $foreground = $this->downsample($foreground);
        $background = $this->downsample($background);

        if ($foreground === $this->currentForeground && $background === $this->currentBackground && $attributes === $this->currentAttributes) {
            return '';
        }

        $removedAttributes = $this->currentAttributes & ~$attributes;
        $sgrParts = [];

        if ($removedAttributes !== 0) {
            $sgrParts[] = '0';
            if ($attributes !== 0) {
                $sgrParts[] = $this->attributesToSgr($attributes);
            }
            if ($foreground !== 0) {
                $sgrParts[] = $this->colorToSgr($foreground, background: false);
            }
            if ($background !== 0) {
                $sgrParts[] = $this->colorToSgr($background, background: true);
            }
        } else {
            $addedAttributes = $attributes & ~$this->currentAttributes;
            if ($addedAttributes !== 0) {
                $sgrParts[] = $this->attributesToSgr($addedAttributes);
            }
            if ($foreground !== $this->currentForeground) {
                $sgrParts[] = $this->colorToSgr($foreground, background: false);
            }
            if ($background !== $this->currentBackground) {
                $sgrParts[] = $this->colorToSgr($background, background: true);
            }
        }

        $this->currentForeground = $foreground;
        $this->currentBackground = $background;
        $this->currentAttributes = $attributes;

        return $sgrParts !== [] ? Ansi::sgr(implode(';', $sgrParts)) : '';
    }

    private static function moveCursor(int $targetX, int $targetY, int $currentX, int $currentY): string
    {
        if ($targetY === $currentY) {
            $deltaX = $targetX - $currentX;

            return $deltaX === 1 ? Ansi::CURSOR_RIGHT_ONE : Ansi::cursorRight($deltaX);
        }

        if ($targetX === $currentX) {
            $deltaY = $targetY - $currentY;

            return $deltaY === 1 ? Ansi::CURSOR_DOWN_ONE : Ansi::cursorDown($deltaY);
        }

        return Ansi::cursorTo($targetY, $targetX);
    }

    private function downsample(int $color): int
    {
        if ($this->colorProfile === ColorProfile::TrueColor) {
            return $color;
        }

        if (!isset($this->downsampleCache[$color]) && count($this->downsampleCache) >= self::COLOR_CACHE_LIMIT) {
            $this->downsampleCache = [];
        }

        return $this->downsampleCache[$color] ??= Color::fromValue($color)->downsampleTo($this->colorProfile)->value;
    }

    private function colorToSgr(int $color, bool $background): string
    {
        if ($background) {
            if (!isset($this->backgroundSgrCache[$color]) && count($this->backgroundSgrCache) >= self::COLOR_CACHE_LIMIT) {
                $this->backgroundSgrCache = [];
            }

            return $this->backgroundSgrCache[$color]
                ??= Color::fromValue($color)->toSgr(true);
        }

        if (!isset($this->foregroundSgrCache[$color]) && count($this->foregroundSgrCache) >= self::COLOR_CACHE_LIMIT) {
            $this->foregroundSgrCache = [];
        }

        return $this->foregroundSgrCache[$color]
            ??= Color::fromValue($color)->toSgr(false);
    }

    private function attributesToSgr(int $attributes): string
    {
        if (isset($this->attributeSgrCache[$attributes])) {
            return $this->attributeSgrCache[$attributes];
        }

        $codes = [];
        if (($attributes & Attr::BOLD) !== 0) {
            $codes[] = '1';
        }
        if (($attributes & Attr::FAINT) !== 0) {
            $codes[] = '2';
        }
        if (($attributes & Attr::ITALIC) !== 0) {
            $codes[] = '3';
        }
        if (($attributes & Attr::UNDERLINE) !== 0) {
            $codes[] = '4';
        }
        if (($attributes & Attr::BLINK) !== 0) {
            $codes[] = '5';
        }
        if (($attributes & Attr::REVERSE) !== 0) {
            $codes[] = '7';
        }
        if (($attributes & Attr::STRIKETHROUGH) !== 0) {
            $codes[] = '9';
        }

        return $this->attributeSgrCache[$attributes] = implode(';', $codes);
    }
}
