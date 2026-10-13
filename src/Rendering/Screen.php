<?php

declare(strict_types=1);

namespace Elmphant\Tui\Rendering;

use Elmphant\Tui\Input\ColorProfile;
use Elmphant\Tui\Input\TerminalOutput;
use LogicException;
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
            : $this->renderDiff($backBuffer);
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

    public function clear(): void
    {
        $this->front = null;
        $this->cursorAsserted = false;
        $this->lastShape = null;
        $this->shown = false;
        $this->resetCurrentStyle();
        $this->terminal->write(Ansi::ERASE_DISPLAY . Ansi::CURSOR_HOME . Ansi::RESET_STYLE);
        $this->terminal->flush();
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

    private function renderDiff(Frame $backBuffer): string
    {
        $front = $this->front;
        if ($front === null) {
            throw new LogicException('Cannot diff before the front buffer is initialized');
        }

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
        $needMove = true;

        for ($row = 0; $row < $backBuffer->height; ++$row) {
            if (!$backBuffer->isRowDirty($row)) {
                $needMove = true;
                continue;
            }

            $rowOffset = $row * $width;
            $column = 0;

            while ($column < $width) {
                $index = $rowOffset + $column;

                if ($backCharacters[$index] === $frontCharacters[$index]
                    && $backForegrounds[$index] === $frontForegrounds[$index]
                    && $backBackgrounds[$index] === $frontBackgrounds[$index]
                    && $backAttributes[$index] === $frontAttributes[$index]) {
                    $needMove = true;
                    $column += max(1, $backWidths[$index]);
                    continue;
                }

                if ($needMove || $cursorX !== $column || $cursorY !== $row) {
                    $parts[] = self::moveCursor($column, $row, $cursorX, $cursorY);
                    $cursorX = $column;
                    $cursorY = $row;
                    $needMove = false;
                }

                $foreground = $backForegrounds[$index];
                $background = $backBackgrounds[$index];
                $attributes = $backAttributes[$index];

                $parts[] = $this->styleDelta($foreground, $background, $attributes);

                $runCharacters = [$backCharacters[$index]];
                $nextColumn = $column + max(1, $backWidths[$index]);

                while ($nextColumn < $width) {
                    $nextIndex = $rowOffset + $nextColumn;

                    if ($backCharacters[$nextIndex] === $frontCharacters[$nextIndex]
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
                    if ($nextCharacter === '') {
                        ++$nextColumn;
                        continue;
                    }

                    $runCharacters[] = $nextCharacter;
                    $nextColumn += max(1, $backWidths[$nextIndex]);
                }

                $parts[] = implode('', $runCharacters);
                $cursorX = $nextColumn;
                $column = $nextColumn;
            }

            $needMove = true;
        }

        return $parts;
    }

    private function renderFull(Frame $backBuffer): string
    {
        $width = $backBuffer->width;
        $this->resetCurrentStyle();
        $output = [];

        for ($row = 0; $row < $backBuffer->height; ++$row) {
            if ($row === 0) {
                $output[] = Ansi::HIDE_CURSOR . Ansi::ERASE_DISPLAY . Ansi::CURSOR_HOME . Ansi::RESET_STYLE;
            } else {
                $output[] = Ansi::cursorToRowStart($row);
            }

            $rowOffset = $row * $width;
            $column = 0;

            while ($column < $width) {
                $index = $rowOffset + $column;
                $character = $backBuffer->characters[$index];

                if ($character === '') {
                    ++$column;
                    continue;
                }

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
        $character = $frame->characters[$rowOffset + $startColumn];
        $parts = [$character];
        $nextColumn = $startColumn + max(1, $frame->widths[$rowOffset + $startColumn]);

        while ($nextColumn < $width) {
            $nextIndex = $rowOffset + $nextColumn;
            $nextCharacter = $frame->characters[$nextIndex];

            if ($nextCharacter === '') {
                ++$nextColumn;
                continue;
            }

            if (!$this->cellHasStyle($frame, $nextIndex, $foreground, $background, $attributes)) {
                break;
            }

            $parts[] = $nextCharacter;
            $nextColumn += max(1, $frame->widths[$nextIndex]);
        }

        return [implode('', $parts), $nextColumn];
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
                $sgrParts[] = $foreground !== 0 ? $this->colorToSgr($foreground, background: false)
                    : '39';
            }
            if ($background !== $this->currentBackground) {
                $sgrParts[] = $background !== 0 ? $this->colorToSgr($background, background: true)
                    : '49';
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
            if ($deltaX === 0) {
                return '';
            }
            if ($deltaX === 1) {
                return Ansi::CURSOR_RIGHT_ONE;
            }

            return $deltaX > 0 ? Ansi::cursorRight($deltaX) : Ansi::cursorLeft(-$deltaX);
        }

        if ($targetX === $currentX) {
            $deltaY = $targetY - $currentY;
            if ($deltaY === 1) {
                return Ansi::CURSOR_DOWN_ONE;
            }

            return $deltaY > 0 ? Ansi::cursorDown($deltaY) : Ansi::cursorUp(-$deltaY);
        }

        return Ansi::cursorTo($targetY, $targetX);
    }

    private function downsample(int $color): int
    {
        if ($this->colorProfile === ColorProfile::TrueColor || $color === 0) {
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
