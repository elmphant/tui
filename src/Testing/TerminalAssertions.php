<?php

declare(strict_types=1);

namespace Elmphant\Tui\Testing;

use Elmphant\Tui\Text\CharWidth;

trait TerminalAssertions
{
    public function assertScreenContains(string $text, ScreenBuffer $screen): void
    {
        static::assertStringContainsString($text, $screen->plainText());
    }

    public function assertTextAt(int $column, int $row, string $expected, ScreenBuffer $screen): void
    {
        static::assertSame($expected, $screen->textAt($column, $row, CharWidth::stringWidth($expected)));
    }

    public function assertCursorAt(int $column, int $row, ScreenBuffer $screen): void
    {
        $position = $screen->cursorPosition();
        static::assertSame($column, $position->col, "Cursor column mismatch: expected {$column}, got {$position->col}");
        static::assertSame($row, $position->row, "Cursor row mismatch: expected {$row}, got {$position->row}");
    }

    public function assertCursorHidden(ScreenBuffer $screen): void
    {
        static::assertFalse($screen->isCursorVisible(), 'Expected cursor to be hidden');
    }
}
