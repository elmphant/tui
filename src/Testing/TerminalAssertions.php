<?php

declare(strict_types=1);

namespace Elmphant\Tui\Testing;

use Elmphant\Tui\Rendering\CharWidth;

trait TerminalAssertions // @phpstan-ignore trait.unused (public consumer trait is analysed in its test suite)
{
    public function assertScreenContains(string $text, ScreenBuffer $screen): void
    {
        static::assertStringContainsString($text, $screen->getPlainText());
    }

    public function assertTextAt(int $row, int $col, string $expected, ScreenBuffer $screen): void
    {
        static::assertSame($expected, $screen->getTextAt($row, $col, CharWidth::stringWidth($expected)));
    }

    public function assertCursorAt(int $row, int $col, ScreenBuffer $screen): void
    {
        $pos = $screen->getCursorPosition();
        static::assertSame($row, $pos->row, "Cursor row mismatch: expected {$row}, got {$pos->row}");
        static::assertSame($col, $pos->col, "Cursor col mismatch: expected {$col}, got {$pos->col}");
    }

    public function assertCursorHidden(ScreenBuffer $screen): void
    {
        static::assertFalse($screen->isCursorVisible(), 'Expected cursor to be hidden');
    }
}
