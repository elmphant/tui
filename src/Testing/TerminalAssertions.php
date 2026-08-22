<?php

declare(strict_types=1);

namespace Elmphant\Tui\Testing;

use Elmphant\Tui\Rendering\CharWidth;

trait TerminalAssertions
{
    public function assertScreenContains(string $text, ScreenBuffer $screen): void
    {
        static::assertStringContainsString($text, $screen->plainText());
    }

    public function assertTextAt(int $row, int $col, string $expected, ScreenBuffer $screen): void
    {
        static::assertSame($expected, $screen->textAt($row, $col, CharWidth::stringWidth($expected)));
    }

    public function assertCursorAt(int $row, int $col, ScreenBuffer $screen): void
    {
        $pos = $screen->cursorPosition();
        static::assertSame($row, $pos->row, "Cursor row mismatch: expected {$row}, got {$pos->row}");
        static::assertSame($col, $pos->col, "Cursor col mismatch: expected {$col}, got {$pos->col}");
    }

    public function assertCursorHidden(ScreenBuffer $screen): void
    {
        static::assertFalse($screen->isCursorVisible(), 'Expected cursor to be hidden');
    }
}
