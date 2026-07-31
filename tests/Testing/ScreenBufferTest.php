<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Testing;

use Elmphant\Tui\Rendering\CursorShape;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Testing\ScreenBuffer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ScreenBufferTest extends TestCase
{
    #[Test]
    public function createsAUnicodeSafeScreenDirectlyFromAFrame(): void
    {
        $frame = new Frame(8, 2);
        $frame->putString(0, 0, '世é');
        $frame->putString(0, 1, 'row two');
        $frame->setCursorPosition(4, 1, CursorShape::SteadyBar);

        $screen = ScreenBuffer::fromFrame($frame);

        self::assertSame('世é', $screen->getTextAt(0, 0, 3));
        self::assertSame('row two', $screen->getTextAt(1, 0, 7));
        self::assertSame(1, $screen->getCursorPosition()?->row);
        self::assertSame(4, $screen->getCursorPosition()?->col);
        self::assertSame(CursorShape::SteadyBar, $screen->getCursorShape());
        self::assertTrue($screen->isCursorVisible());
    }

    #[Test]
    public function parsesUnicodeAnsiOutputUsingTerminalCells(): void
    {
        $screen = new ScreenBuffer("\x1b[1;1H世é", 8, 1);

        self::assertSame('世é', $screen->getTextAt(0, 0, 3));
        self::assertSame(3, $screen->getCursorPosition()?->col);
    }

    #[Test]
    public function ansiCapturePreservesSpacesAndSkipsZeroWidthControls(): void
    {
        $screen = new ScreenBuffer(" \u{0080}A", 4, 1);

        self::assertSame(' A', $screen->getTextAt(0, 0, 2));
        self::assertSame(2, $screen->getCursorPosition()?->col);
    }

    #[Test]
    public function ansiCaptureSkipsAStandaloneZeroWidthGrapheme(): void
    {
        $screen = new ScreenBuffer("\u{0300}", 3, 1);

        self::assertSame(' ', $screen->getTextAt(0, 0, 1));
        self::assertSame(0, $screen->getCursorPosition()?->col);
    }

    #[Test]
    public function aNarrowGraphemeCanFollowAWideGraphemeThatDoesNotFit(): void
    {
        $screen = new ScreenBuffer('A世B', 2, 1);

        self::assertSame('AB', $screen->getLine(0));
    }

    #[Test]
    public function plainTextAtPosition(): void
    {
        $screen = new ScreenBuffer("\x1b[1;1HHello", 80, 24);
        $this->assertSame('Hello', $screen->getTextAt(0, 0, 5));
    }

    #[Test]
    public function cursorPositioningPlacesTextAtCorrectLocation(): void
    {
        $screen = new ScreenBuffer("\x1b[1;1HA\x1b[1;5HB", 80, 24);
        $this->assertSame('A', $screen->getTextAt(0, 0, 1));
        $this->assertSame('B', $screen->getTextAt(0, 4, 1));
    }

    #[Test]
    public function getLineReturnsFullRow(): void
    {
        $screen = new ScreenBuffer("\x1b[1;1HFirst\x1b[2;1HSecond", 80, 24);
        $this->assertSame('First', rtrim($screen->getLine(0)));
        $this->assertSame('Second', rtrim($screen->getLine(1)));
    }

    #[Test]
    public function getPlainTextContainsAllRows(): void
    {
        $screen = new ScreenBuffer("\x1b[1;1HRow 1\x1b[2;1HRow 2", 80, 24);
        $text = $screen->getPlainText();
        $this->assertStringContainsString('Row 1', $text);
        $this->assertStringContainsString('Row 2', $text);
    }

    #[Test]
    public function sgrStyleDoesNotAffectText(): void
    {
        $screen = new ScreenBuffer("\x1b[1;1H\x1b[31mRed\x1b[0m", 80, 24);
        $this->assertSame('Red', $screen->getTextAt(0, 0, 3));
    }

    #[Test]
    public function eraseInLineClearsFromCursor(): void
    {
        $screen = new ScreenBuffer("\x1b[1;1HABCDEF\x1b[1;4H\x1b[K", 80, 24);
        $this->assertSame('ABC', rtrim($screen->getLine(0)));
    }

    #[Test]
    public function getCursorPositionFromCup(): void
    {
        $screen = new ScreenBuffer("\x1b[5;10H", 80, 24);
        $pos = $screen->getCursorPosition();
        $this->assertSame(4, $pos->row);
        $this->assertSame(9, $pos->col);
    }

    #[Test]
    public function getCursorShapeFromDecscusr(): void
    {
        $screen = new ScreenBuffer("\x1b[5 q", 80, 24);
        $this->assertSame(CursorShape::BlinkingBar, $screen->getCursorShape());
    }

    #[Test]
    public function cursorHiddenBySequence(): void
    {
        $screen = new ScreenBuffer("\x1b[?25l", 80, 24);
        $this->assertFalse($screen->isCursorVisible());
    }

    #[Test]
    public function cursorShownAgainAfterHide(): void
    {
        $screen = new ScreenBuffer("\x1b[?25l\x1b[?25h", 80, 24);
        $this->assertTrue($screen->isCursorVisible());
    }

    #[Test]
    public function otherPrivateModesDoNotAffectCursorVisibility(): void
    {
        $screen = new ScreenBuffer("\x1b[?25l\x1b[?1049h\x1b[?1000h", 80, 24);
        $this->assertFalse($screen->isCursorVisible(), 'Only mode ?25 controls visibility; alt-screen/mouse modes must not flip it back');
    }

    #[Test]
    public function emptyBufferRendersFullGridOfSpaces(): void
    {
        $screen = new ScreenBuffer('', 5, 2);
        $this->assertSame('     ', $screen->getLine(0));
        $this->assertSame('     ', $screen->getLine(1));
        $this->assertSame("\n", $screen->getPlainText(), 'Plain text right-trims each of the two blank rows');
    }

    #[Test]
    public function getLinePadsToExactWidth(): void
    {
        $screen = new ScreenBuffer('Hi', 5, 2);
        $this->assertSame('Hi   ', $screen->getLine(0));
        $this->assertSame("Hi\n", $screen->getPlainText());
    }

    #[Test]
    public function printingAdvancesTheCursor(): void
    {
        $screen = new ScreenBuffer('AB', 80, 24);
        $pos = $screen->getCursorPosition();
        $this->assertSame(0, $pos->row);
        $this->assertSame(2, $pos->col);
    }

    #[Test]
    public function writesBeyondTheRightEdgeAreDropped(): void
    {
        $screen = new ScreenBuffer("\x1b[1;5HXYZ", 5, 2);
        $this->assertSame('    X', $screen->getLine(0), 'Only the cell inside the grid is written; Y and Z fall off the edge');
    }

    #[Test]
    public function cupClampsToGridBounds(): void
    {
        $screen = new ScreenBuffer("\x1b[99;99H", 10, 5);
        $pos = $screen->getCursorPosition();
        $this->assertSame(4, $pos->row);
        $this->assertSame(9, $pos->col);
    }

    #[Test]
    public function cupRowZeroClampsToFirstRow(): void
    {
        $screen = new ScreenBuffer("\x1b[0;0HA", 10, 5);
        $this->assertSame('A', $screen->getTextAt(0, 0, 1));
    }

    #[Test]
    public function cupWithoutParamsHomesTheCursor(): void
    {
        $screen = new ScreenBuffer("\x1b[3;3H\x1b[HX", 10, 5);
        $this->assertSame('X', $screen->getTextAt(0, 0, 1));
    }

    #[Test]
    public function cupWithOnlyARowUsesTheDefaultColumn(): void
    {
        $screen = new ScreenBuffer("\x1b[3HA", 8, 4);

        self::assertSame('A', $screen->getTextAt(2, 0, 1));
        self::assertSame(2, $screen->getCursorPosition()?->row);
        self::assertSame(1, $screen->getCursorPosition()?->col);
    }

    #[Test]
    public function hvpFinalByteFPositionsLikeCup(): void
    {
        $screen = new ScreenBuffer("\x1b[2;3fZ", 10, 5);
        $this->assertSame('Z', $screen->getTextAt(1, 2, 1));
    }

    #[Test]
    public function controlCharactersAreSkippedWithoutMovingTheCursor(): void
    {
        $screen = new ScreenBuffer("A\nB\tC", 80, 24);
        $this->assertSame('ABC', $screen->getTextAt(0, 0, 3));
    }

    #[Test]
    public function trailingBareEscapeDoesNotLoopOrPrint(): void
    {
        $screen = new ScreenBuffer("AB\x1b", 80, 24);
        $this->assertSame('AB', rtrim($screen->getLine(0)));
    }

    #[Test]
    public function truncatedCsiAtEndOfStreamIsIgnored(): void
    {
        $screen = new ScreenBuffer("AB\x1b[12", 80, 24);
        $this->assertSame('AB', rtrim($screen->getLine(0)));
    }

    #[Test]
    public function unknownCsiWithIntermediatesDoesNotCorruptFollowingText(): void
    {
        $screen = new ScreenBuffer("\x1b[2 zX", 80, 24);
        $this->assertSame('X', $screen->getTextAt(0, 0, 1));
    }

    #[Test]
    public function eraseInLineClearsToTheRightEdgeOnly(): void
    {
        $screen = new ScreenBuffer("\x1b[1;1HABCDEF\x1b[2;1Hxxxxxx\x1b[1;3H\x1b[K", 6, 2);
        $this->assertSame('AB    ', $screen->getLine(0), 'Erase clears from the cursor to the right edge');
        $this->assertSame('xxxxxx', $screen->getLine(1), 'Erase never touches other rows');
    }

    #[Test]
    public function decscusrWithInvalidShapeKeepsThePreviousShape(): void
    {
        $screen = new ScreenBuffer("\x1b[9 q", 80, 24);
        $this->assertSame(CursorShape::BlinkingBlock, $screen->getCursorShape());
    }

    #[Test]
    public function qFinalWithoutSpaceIntermediateIsNotAShapeChange(): void
    {
        $screen = new ScreenBuffer("\x1b[5q", 80, 24);
        $this->assertSame(CursorShape::BlinkingBlock, $screen->getCursorShape());
    }

    #[Test]
    public function getTextAtOutsideWrittenAreaReturnsSpaces(): void
    {
        $screen = new ScreenBuffer('A', 10, 5);
        $this->assertSame('   ', $screen->getTextAt(4, 7, 3));
    }

    #[Test]
    public function cellsPastTheRightEdgeAreNeverWritten(): void
    {
        $screen = new ScreenBuffer("\x1b[1;5HXYZ", 5, 2);
        $this->assertSame('  ', $screen->getTextAt(0, 5, 2), 'Overflowing writes must not land in cells beyond the grid width');
    }

    #[Test]
    public function trailingEscapeBracketPrintsNothing(): void
    {
        $screen = new ScreenBuffer("A\x1b[", 80, 24);
        $this->assertSame('A', rtrim($screen->getLine(0)), 'A truncated CSI introducer at end of stream must not leak a literal [');
    }

    #[Test]
    public function slashIntermediateIsConsumedWithItsFinalByte(): void
    {
        $screen = new ScreenBuffer("\x1b[5/q", 80, 24);
        $this->assertSame('', rtrim($screen->getLine(0)), 'The final byte of a CSI with a / intermediate must not print as text');
        $this->assertSame(CursorShape::BlinkingBlock, $screen->getCursorShape(), 'q with a non-space intermediate is not DECSCUSR');
    }

    #[Test]
    public function multipleIntermediatesAccumulateInsteadOfOverwriting(): void
    {
        $screen = new ScreenBuffer("\x1b[5/ q", 80, 24);
        $this->assertSame(CursorShape::BlinkingBlock, $screen->getCursorShape(), 'Intermediates "/ " are not the single-space DECSCUSR marker');
    }

    #[Test]
    public function decscusrWithoutParamDefaultsToBlinkingBlock(): void
    {
        $screen = new ScreenBuffer("\x1b[3 q\x1b[ q", 80, 24);
        $this->assertSame(CursorShape::BlinkingBlock, $screen->getCursorShape(), 'Param-less DECSCUSR resets to the default shape (1)');
    }

    #[Test]
    public function unknownFinalByteOnMode25DoesNotToggleVisibility(): void
    {
        $screen = new ScreenBuffer("\x1b[?25X", 80, 24);
        $this->assertTrue($screen->isCursorVisible(), 'Only h/l finals switch mode ?25');
    }
}
