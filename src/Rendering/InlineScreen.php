<?php

declare(strict_types=1);

namespace Elmphant\Tui\Rendering;

use Elmphant\Tui\Terminal\Ansi;
use Elmphant\Tui\Terminal\ColorProfile;
use Elmphant\Tui\Terminal\CursorShape;
use Elmphant\Tui\Terminal\TerminalOutput;
use Elmphant\Tui\Text\CharWidth;
use Elmphant\Tui\Text\TerminalText;

final class InlineScreen
{
    private const ATTRIBUTE_CODES = [
        Attr::BOLD => '1',
        Attr::FAINT => '2',
        Attr::ITALIC => '3',
        Attr::UNDERLINE => '4',
        Attr::BLINK => '5',
        Attr::REVERSE => '7',
        Attr::STRIKETHROUGH => '9',
    ];

    private int $rows = 0;
    private int $cursorRow = 0;
    private bool $shown = false;
    private ?CursorShape $shape = null;
    private bool $printedLast = false;

    public function __construct(
        private readonly TerminalOutput $terminal,
        private readonly ColorProfile $colorProfile = ColorProfile::TrueColor,
    ) {
    }

    public function flush(Frame $backBuffer): void
    {
        $rows = [];
        for ($row = 0; $row < $backBuffer->height; ++$row) {
            $rows[] = $this->rowText($backBuffer, $row);
        }

        $erased = $backBuffer->height < $this->rows ? "\r\n" . Ansi::ERASE_BELOW . Ansi::cursorUp(1) : '';
        $painted = self::up($this->cursorRow) . "\r" . implode("\r\n", $rows) . $erased;
        $this->rows = $backBuffer->height;
        $this->cursorRow = $backBuffer->height - 1;
        $this->printedLast = false;

        $this->terminal->write(Ansi::SYNC_START . $painted . $this->cursorPlacedAt($backBuffer->cursorPosition) . Ansi::SYNC_END);
        $this->terminal->flush();
    }

    /**
     * @param list<string> $lines
     */
    public function printAbove(array $lines, int $width): void
    {
        $printed = self::up($this->cursorRow) . "\r" . Ansi::ERASE_BELOW;
        foreach (explode("\n", implode("\n", $lines)) as $line) {
            $printed .= CharWidth::truncateToWidth(TerminalText::plain($line), $width) . "\r\n";
        }
        $this->rows = 0;
        $this->cursorRow = 0;
        $this->printedLast = true;

        $this->terminal->write(Ansi::SYNC_START . $printed . Ansi::SYNC_END);
        $this->terminal->flush();
    }

    public function printedLast(): bool
    {
        return $this->printedLast;
    }

    /**
     * Each frame repaints the whole region.
     */
    public function forget(): void
    {
    }

    public function parkBelow(): string
    {
        if ($this->rows === 0) {
            return "\r";
        }

        $below = $this->rows - 1 - $this->cursorRow;

        return ($below > 0 ? Ansi::cursorDown($below) : '') . "\r\n";
    }

    private function cursorPlacedAt(?CursorPosition $position): string
    {
        if ($position === null) {
            $hide = $this->shown ? Ansi::HIDE_CURSOR : '';
            $this->shown = false;

            return $hide;
        }

        $placed = self::up($this->cursorRow - $position->row) . "\r" . ($position->col > 0 ? Ansi::cursorRight($position->col) : '')
            . ($this->shape === $position->shape ? '' : Ansi::cursorShape($position->shape))
            . ($this->shown ? '' : Ansi::SHOW_CURSOR);
        $this->cursorRow = $position->row;
        $this->shape = $position->shape;
        $this->shown = true;

        return $placed;
    }

    private function rowText(Frame $frame, int $row): string
    {
        $offset = $row * $frame->width;
        $end = $offset + $frame->width;
        while ($end > $offset && self::isBlank($frame, $end - 1)) {
            --$end;
        }

        $text = '';
        $style = '';
        for ($index = $offset; $index < $end; ++$index) {
            $cellStyle = $this->styleOf($frame, $index);
            if ($cellStyle !== $style) {
                $text .= $cellStyle === '' ? Ansi::RESET_STYLE : Ansi::sgr('0;' . $cellStyle);
                $style = $cellStyle;
            }
            $text .= $frame->characters[$index];
        }

        $text = $style === '' ? $text : $text . Ansi::RESET_STYLE;

        // A row written to its last column leaves the cursor on that column, so an erase would take the last character.
        return $end === $offset + $frame->width ? $text : $text . Ansi::ERASE_TO_LINE_END;
    }

    private static function isBlank(Frame $frame, int $index): bool
    {
        return $frame->characters[$index] === ' ' && $frame->backgrounds[$index] === 0 && $frame->attributes[$index] === 0;
    }

    private function styleOf(Frame $frame, int $index): string
    {
        $foreground = $frame->foregrounds[$index];
        $background = $frame->backgrounds[$index];
        $attributes = $frame->attributes[$index];
        if ($foreground === 0 && $background === 0 && $attributes === 0) {
            return '';
        }

        $codes = [];
        foreach (self::ATTRIBUTE_CODES as $attribute => $code) {
            if (($attributes & $attribute) !== 0) {
                $codes[] = $code;
            }
        }
        $codes[] = Color::fromValue($foreground)->downsampleTo($this->colorProfile)->toSgr(false);
        $codes[] = Color::fromValue($background)->downsampleTo($this->colorProfile)->toSgr(true);

        return implode(';', $codes);
    }

    private static function up(int $rows): string
    {
        return $rows > 0 ? Ansi::cursorUp($rows) : '';
    }
}
