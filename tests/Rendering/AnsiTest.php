<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Rendering;

use Elmphant\Tui\Rendering\Ansi;
use Elmphant\Tui\Rendering\CursorShape;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AnsiTest extends TestCase
{
    #[Test]
    public function cursorToRowStartProducesCorrectEscapeSequenceForRowZero(): void
    {
        self::assertSame("\x1b[1;1H", Ansi::cursorToRowStart(0));
    }

    #[Test]
    public function cursorToRowStartConvertsZeroBasedRowToOneBased(): void
    {
        self::assertSame("\x1b[6;1H", Ansi::cursorToRowStart(5));
    }

    #[Test]
    public function cursorToRowStartStartsWithEscapeBracket(): void
    {
        $result = Ansi::cursorToRowStart(3);
        self::assertStringStartsWith("\x1b[", $result);
    }

    #[Test]
    public function cursorToRowStartEndsWithSemicolonOneH(): void
    {
        $result = Ansi::cursorToRowStart(3);
        self::assertStringEndsWith(';1H', $result);
    }

    #[Test]
    public function cursorToRowStartContainsRowNumberBetweenBracketAndSemicolon(): void
    {
        $result = Ansi::cursorToRowStart(9);
        self::assertSame("\x1b[10;1H", $result);
    }

    #[Test]
    public function cursorToRowStartRowOffByOneIsDetected(): void
    {
        $result = Ansi::cursorToRowStart(1);
        self::assertSame("\x1b[2;1H", $result);
        self::assertNotSame("\x1b[1;1H", $result, 'Row 1 should produce row number 2, not 1');
        self::assertNotSame("\x1b[3;1H", $result, 'Row 1 should produce row number 2, not 3');
        self::assertNotSame("\x1b[0;1H", $result, 'Row 1 should produce row number 2, not 0');
    }

    #[Test]
    public function cursorShapeProducesDecSetCursorStyleSequence(): void
    {
        self::assertSame("\x1b[5 q", Ansi::cursorShape(CursorShape::BlinkingBar));
        self::assertSame("\x1b[2 q", Ansi::cursorShape(CursorShape::SteadyBlock));
        self::assertSame("\x1b[4 q", Ansi::cursorShape(CursorShape::SteadyUnderline));
    }

    #[Test]
    public function clipboardCarriesTheTextToTheTerminalBase64Encoded(): void
    {
        self::assertSame("\x1b]52;c;" . base64_encode('one two') . "\x07", Ansi::clipboard('one two'));
    }
}
