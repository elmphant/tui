<?php

declare(strict_types=1);

namespace Elmphant\Tui\Rendering;

use Elmphant\Tui\Input\ColorProfile;
use Elmphant\Tui\Input\TerminalOutputInterface;
use LogicException;

final class Screen
{
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

    public function __construct(
        private readonly TerminalOutputInterface $terminal,
        private readonly ColorProfile $colorProfile = ColorProfile::TrueColor,
    ) {
    }

    public function flush(Frame $backBuffer): void
    {
        if ($this->front === null || $this->front->width !== $backBuffer->width || $this->front->height !== $backBuffer->height) {
            $this->renderFull($backBuffer);
        } else {
            $this->renderDiff($backBuffer);
        }

        $this->emitCursor($backBuffer);
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
        $this->resetCurrentStyle();
        $this->terminal->write(Ansi::ERASE_DISPLAY . Ansi::CURSOR_HOME . Ansi::RESET_STYLE);
        $this->terminal->flush();
    }

    private function emitCursor(Frame $backBuffer): void
    {
        $position = $backBuffer->cursorPosition;

        if ($this->cursorAsserted && $this->matchesLastCursor($position)) {
            return;
        }

        $this->terminal->write($position === null
            ? Ansi::HIDE_CURSOR
            : Ansi::cursorShape($position->shape) . Ansi::cursorTo($position->row, $position->col) . Ansi::SHOW_CURSOR);

        $this->lastCursor = $position;
        $this->cursorAsserted = true;
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

    private function renderDiff(Frame $backBuffer): void
    {
        $width = $backBuffer->width;
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

                if ($this->cellIsUnchanged($backBuffer, $index)) {
                    $needMove = true;
                    $column += max(1, $backBuffer->widths[$index]);
                    continue;
                }

                if ($needMove || $cursorX !== $column || $cursorY !== $row) {
                    $parts[] = self::moveCursor($column, $row, $cursorX, $cursorY);
                    $cursorX = $column;
                    $cursorY = $row;
                    $needMove = false;
                }

                $foreground = $backBuffer->foregrounds[$index];
                $background = $backBuffer->backgrounds[$index];
                $attributes = $backBuffer->attributes[$index];

                $parts[] = $this->styleDelta($foreground, $background, $attributes);
                [$runCharacters, $nextColumn] = $this->collectChangedRun($backBuffer, $rowOffset, $column, $width, $foreground, $background, $attributes);
                $parts[] = $runCharacters;
                $cursorX = $nextColumn;
                $column = $nextColumn;
            }

            $needMove = true;
        }

        if ($parts !== []) {
            $this->emitOutput($parts);
        }

        $this->copyDirtyRows($backBuffer);
        $backBuffer->clearDirty();
    }

    private function renderFull(Frame $backBuffer): void
    {
        $width = $backBuffer->width;
        $this->resetCurrentStyle();
        $output = [Ansi::SYNC_START];

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

        $output[] = Ansi::RESET_STYLE . Ansi::SYNC_END;
        $this->resetCurrentStyle();
        $this->terminal->write(implode('', $output));
        $this->terminal->flush();
        $this->cursorAsserted = false;
        $this->snapshotFrontBuffer($backBuffer);
        $backBuffer->clearDirty();
    }

    /**
     * Collect consecutive characters with the same style that have changed from front buffer.
     *
     * @return array{string, int} [characters, nextColumn]
     */
    private function collectChangedRun(Frame $backBuffer, int $rowOffset, int $startColumn, int $width, int $foreground, int $background, int $attributes): array
    {
        $character = $backBuffer->characters[$rowOffset + $startColumn];
        $parts = [$character];
        $nextColumn = $startColumn + max(1, $backBuffer->widths[$rowOffset + $startColumn]);

        while ($nextColumn < $width) {
            $nextIndex = $rowOffset + $nextColumn;

            if ($this->cellIsUnchanged($backBuffer, $nextIndex)) {
                break;
            }

            if (!$this->cellHasStyle($backBuffer, $nextIndex, $foreground, $background, $attributes)) {
                break;
            }

            $nextCharacter = $backBuffer->characters[$nextIndex];
            if ($nextCharacter === '') {
                ++$nextColumn;
                continue;
            }

            $parts[] = $nextCharacter;
            $nextColumn += max(1, $backBuffer->widths[$nextIndex]);
        }

        return [implode('', $parts), $nextColumn];
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

    private function cellIsUnchanged(Frame $backBuffer, int $index): bool
    {
        $front = $this->front;
        if ($front === null) {
            return false;
        }

        return $backBuffer->characters[$index] === $front->characters[$index]
            && $backBuffer->foregrounds[$index] === $front->foregrounds[$index]
            && $backBuffer->backgrounds[$index] === $front->backgrounds[$index]
            && $backBuffer->attributes[$index] === $front->attributes[$index];
    }

    private function cellHasStyle(Frame $frame, int $index, int $foreground, int $background, int $attributes): bool
    {
        return $frame->foregrounds[$index] === $foreground
            && $frame->backgrounds[$index] === $background
            && $frame->attributes[$index] === $attributes;
    }

    /**
     * @param list<string> $parts
     */
    private function emitOutput(array $parts): void
    {
        $parts[] = Ansi::RESET_STYLE;
        $this->resetCurrentStyle();
        $this->terminal->write(Ansi::SYNC_START . implode('', $parts) . Ansi::SYNC_END);
        $this->terminal->flush();
        $this->cursorAsserted = false;
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

    private function copyDirtyRows(Frame $backBuffer): void
    {
        $front = $this->front;
        if ($front === null) {
            throw new LogicException('Cannot copy dirty rows before the front buffer is initialized');
        }
        foreach ($backBuffer->dirtyRows as $row => $_) {
            $front->copyRowFrom($backBuffer, $row);
        }
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
                $sgrParts[] = $this->colorToSgr($foreground, false);
            }
            if ($background !== 0) {
                $sgrParts[] = $this->colorToSgr($background, true);
            }
        } else {
            $addedAttributes = $attributes & ~$this->currentAttributes;
            if ($addedAttributes !== 0) {
                $sgrParts[] = $this->attributesToSgr($addedAttributes);
            }
            if ($foreground !== $this->currentForeground) {
                $sgrParts[] = $foreground !== 0 ? $this->colorToSgr($foreground, false)
                    : '39';
            }
            if ($background !== $this->currentBackground) {
                $sgrParts[] = $background !== 0 ? $this->colorToSgr($background, true)
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

        return $this->downsampleCache[$color] ??= Color::fromValue($color)->downsampleTo($this->colorProfile)->value;
    }

    private function colorToSgr(int $color, bool $background): string
    {
        if ($background) {
            return $this->backgroundSgrCache[$color]
                ??= Color::fromValue($color)->toSgr(true);
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
