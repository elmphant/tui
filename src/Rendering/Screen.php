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
    private ?Frame $front = null;
    private readonly Pen $pen;

    /**
     * Track the cursor state last sent to the terminal so unchanged frames produce no output.
     */
    private ?CursorPosition $lastCursor = null;

    private bool $cursorAsserted = false;
    private ?CursorShape $lastShape = null;
    private bool $shown = false;

    public function __construct(
        private readonly TerminalOutput $terminal,
        ColorProfile $colorProfile = ColorProfile::TrueColor,
    ) {
        $this->pen = new Pen($colorProfile);
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
     * Repeating the cursor-shape sequence restarts blinking, so send it only when the shape changes.
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

        $parts[] = $this->pen->closeLink();
        $this->cursorAsserted = false;
        $this->pen->forgetStyle();

        return implode('', $parts) . Ansi::RESET_STYLE;
    }

    /**
     * Compare local cell arrays inline to avoid a method call for every cell in a dirty row.
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
                    $parts[] = $this->pen->closeLink() . self::moveCursor($column, $row, $cursorX, $cursorY);
                    $cursorY = $row;
                }

                $foreground = $backForegrounds[$index];
                $background = $backBackgrounds[$index];
                $attributes = $backAttributes[$index];

                $parts[] = $this->pen->switchTo($foreground, $background, $attributes);

                $runCharacters = [self::cellText($backCharacters[$index], $backWidths[$index], $row, $column)];
                $repaintsTheRest = $repaintsTheRest || Pen::isCluster($backCharacters[$index], $backWidths[$index]);
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
                    $repaintsTheRest = $repaintsTheRest || Pen::isCluster($nextCharacter, $backWidths[$nextIndex]);
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
            // Clear remnants of wide characters that terminals such as tmux may leave after resize reflow.
            if ($row === 0) {
                $output[] = Ansi::HIDE_CURSOR . Ansi::CURSOR_HOME . Ansi::RESET_STYLE . Ansi::ERASE_TO_LINE_END;
            } else {
                $output[] = $this->pen->closeLink() . Ansi::cursorToRowStart($row) . Ansi::ERASE_TO_LINE_END;
            }

            $rowOffset = $row * $width;
            $column = 0;

            while ($column < $width) {
                $index = $rowOffset + $column;
                $foreground = $backBuffer->foregrounds[$index];
                $background = $backBuffer->backgrounds[$index];
                $attributes = $backBuffer->attributes[$index];

                $output[] = $this->pen->switchTo($foreground, $background, $attributes);
                [$runCharacters, $nextColumn] = $this->collectStyledRun($backBuffer, $rowOffset, $column, $width, $foreground, $background, $attributes);
                $output[] = $runCharacters;
                $column = $nextColumn;
            }
        }

        $output[] = $this->pen->closeLink() . Ansi::RESET_STYLE;
        $this->pen->forgetStyle();
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

    private static function cellText(string $character, int $width, int $row, int $column): string
    {
        // Back by absolute position: after a space in the last column the cursor waits to wrap, and a relative move would land one short.
        return Pen::cellText($character, $width, Ansi::cursorTo($row, $column), Ansi::cursorTo($row, $column + $width));
    }

    private function cellHasStyle(Frame $frame, int $index, int $foreground, int $background, int $attributes): bool
    {
        return $frame->foregrounds[$index] === $foreground
            && $frame->backgrounds[$index] === $background
            && $frame->attributes[$index] === $attributes;
    }

    private function snapshotFrontBuffer(Frame $source): void
    {
        if ($this->front === null || $this->front->width !== $source->width || $this->front->height !== $source->height) {
            $this->front = new Frame($source->width, $source->height);
        }
        $this->front->snapshotFrom($source);
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
}
