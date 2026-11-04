<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Rendering;

use Elmphant\Tui\Rendering\AnsiColor;
use Elmphant\Tui\Rendering\Attr;
use Elmphant\Tui\Rendering\Color;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\InlineScreen;
use Elmphant\Tui\Rendering\Line;
use Elmphant\Tui\Rendering\Span;
use Elmphant\Tui\Terminal\Ansi;
use Elmphant\Tui\Terminal\CursorShape;
use Elmphant\Tui\Testing\VirtualTerminal;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class InlineScreenTest extends TestCase
{
    #[Test]
    public function aFirstFrameOfThreeRowsPaintsTheFirstInPlaceAndReachesTheOthersWithTwoNewlines(): void
    {
        $terminal = new VirtualTerminal(10, 24);
        $frame = new Frame(10, 3);
        $frame->putString(0, 0, 'one');
        $frame->putString(0, 1, 'two');
        $frame->putString(0, 2, 'three');

        (new InlineScreen($terminal))->flush($frame);

        self::assertSame(2, substr_count($terminal->output(), "\r\n"));
        self::assertDoesNotMatchRegularExpression('/\x1b\[[\d;]*H/', $terminal->output());
        self::assertStringContainsString("one\x1b[K\r\ntwo\x1b[K\r\nthree\x1b[K", $terminal->output());
    }

    #[Test]
    public function aFrameLeavesTheCursorAtTheStartOfItsFirstRowWhereNoRewrappingCanMoveIt(): void
    {
        $terminal = new VirtualTerminal(10, 24);

        (new InlineScreen($terminal))->flush(self::frameOf('one', 'two', 'three'));

        self::assertStringEndsWith("three\x1b[K\x1b[2A\r" . Ansi::SYNC_END, $terminal->output());
    }

    #[Test]
    public function theNextFrameStartsWhereTheLastLeftTheCursor(): void
    {
        $terminal = new VirtualTerminal(10, 24);
        $screen = new InlineScreen($terminal);
        $screen->flush(self::frameOf('one', 'two', 'three'));
        $terminal->clearOutput();

        $screen->flush(self::frameOf('one', 'two', 'four'));

        self::assertStringStartsWith(Ansi::SYNC_START . "\rone", $terminal->output());
    }

    #[Test]
    public function aShorterFrameErasesTheTallerOneBeforePaintingSoItNeverWritesBelowItsLastRow(): void
    {
        $terminal = new VirtualTerminal(10, 24);
        $screen = new InlineScreen($terminal);
        $screen->flush(self::frameOf('one', 'two', 'three'));
        $terminal->clearOutput();

        $screen->flush(self::frameOf('one', 'two'));

        self::assertSame(Ansi::SYNC_START . "\r\x1b[1C\x1b[J\rone\x1b[K\r\ntwo\x1b[K\x1b[1A\r" . Ansi::SYNC_END, $terminal->output());
    }

    #[Test]
    public function aStyledCellCarriesItsStyleAndTheRowIsErasedInTheDefaultStyle(): void
    {
        $terminal = new VirtualTerminal(10, 24);
        $frame = new Frame(10, 1);
        $frame->putString(0, 0, 'ok', Color::ansi(AnsiColor::Red)->value, 0, Attr::BOLD);

        (new InlineScreen($terminal))->flush($frame);

        self::assertStringContainsString("\x1b[1;31mok\x1b[0m\x1b[K", $terminal->output());
    }

    #[Test]
    public function aRowThatFillsTheWidthIsNotErasedBecauseTheEraseWouldTakeItsLastCharacter(): void
    {
        $terminal = new VirtualTerminal(4, 24);
        $frame = new Frame(4, 2);
        $frame->putString(0, 0, '╭──╮');
        $frame->putString(0, 1, 'ok');

        (new InlineScreen($terminal))->flush($frame);

        self::assertStringContainsString("╭──╮\r\nok\x1b[K", $terminal->output());
    }

    #[Test]
    public function aFrameNoShorterThanTheLastErasesNothingBelowItsFullLastRow(): void
    {
        $terminal = new VirtualTerminal(4, 24);
        $frame = new Frame(4, 1);
        $frame->putString(0, 0, '╰──╯');

        (new InlineScreen($terminal))->flush($frame);

        self::assertStringNotContainsString("\x1b[J", $terminal->output());
    }

    #[Test]
    public function theCaretIsDrawnAsAReversedCellAndTheTerminalCursorStaysHidden(): void
    {
        $terminal = new VirtualTerminal(10, 24);
        $frame = self::frameOf('one', 'two');
        $frame->setCursorPosition(1, 1, CursorShape::SteadyBar);

        (new InlineScreen($terminal))->flush($frame);

        self::assertStringContainsString("\r\nt\x1b[7mw\x1b[0mo\x1b[K", $terminal->output());
        self::assertStringNotContainsString(Ansi::SHOW_CURSOR, $terminal->output());
    }

    #[Test]
    public function aLinkOpensBeforeItsTextAndClosesBeforeTheRowIsErased(): void
    {
        $terminal = new VirtualTerminal(20, 24);
        $frame = new Frame(20, 2);
        $frame->putLine(0, 0, new Line([Span::raw('see '), Span::link('docs', 'https://example.com/docs'), Span::raw(' now')]));
        $frame->putLine(0, 1, new Line([Span::link('docs', 'https://example.com/docs')]));

        (new InlineScreen($terminal))->flush($frame);

        self::assertStringContainsString("see \e]8;;https://example.com/docs\e\\docs\e]8;;\e\\ now\x1b[K", $terminal->output());
        self::assertStringContainsString("\e]8;;https://example.com/docs\e\\docs\e]8;;\e\\\x1b[K", $terminal->output());
    }

    #[Test]
    public function anEmojiOfUnsureWidthClearsItsCellsAndTheCursorReturnsToTheCellAfterIt(): void
    {
        $terminal = new VirtualTerminal(10, 24);

        (new InlineScreen($terminal))->flush(self::frameOf("a❤\u{FE0F}c"));

        self::assertStringContainsString("a  \r\x1b[1C❤\u{FE0F}\r\x1b[3Cc\x1b[K", $terminal->output());
    }

    #[Test]
    public function aLinkFollowedByAnotherIsClosedBeforeTheNextOpens(): void
    {
        $terminal = new VirtualTerminal(20, 24);
        $frame = new Frame(20, 1);
        $frame->putLine(0, 0, new Line([Span::link('a', 'https://a.example'), Span::link('b', 'https://b.example')]));

        (new InlineScreen($terminal))->flush($frame);

        self::assertStringContainsString("\e]8;;https://a.example\e\\a\e]8;;\e\\\e]8;;https://b.example\e\\b", $terminal->output());
    }

    #[Test]
    public function aStyledLinkAtTheEndOfARowIsClosedAndItsStyleReset(): void
    {
        $terminal = new VirtualTerminal(20, 24);
        $frame = new Frame(20, 1);
        $frame->putLine(0, 0, new Line([Span::link('docs', 'https://example.com/docs')->bold()]));

        (new InlineScreen($terminal))->flush($frame);

        self::assertStringContainsString("docs\e]8;;\e\\\x1b[0m\x1b[K", $terminal->output());
    }

    #[Test]
    public function aBoldRowWithoutColourIsResetBeforeTheRowIsErased(): void
    {
        $terminal = new VirtualTerminal(10, 24);
        $frame = new Frame(10, 1);
        $frame->putString(0, 0, 'ok', 0, 0, Attr::BOLD);

        (new InlineScreen($terminal))->flush($frame);

        self::assertStringContainsString("\x1b[1mok\x1b[0m\x1b[K", $terminal->output());
    }

    #[Test]
    public function anEmojiOfUnsureWidthOnALaterRowReturnsToItsOwnColumn(): void
    {
        $terminal = new VirtualTerminal(10, 24);

        (new InlineScreen($terminal))->flush(self::frameOf('one', "❤\u{FE0F}c"));

        self::assertStringContainsString("\r\n  \r❤\u{FE0F}\r\x1b[2Cc\x1b[K", $terminal->output());
    }

    #[Test]
    public function parkingFromAFiveRowRegionGoesDownFourRowsToTheLineBelow(): void
    {
        $screen = new InlineScreen(new VirtualTerminal(10, 24));
        $screen->flush(self::frameOf('1', '2', '3', '4', '5'));

        self::assertSame("\x1b[4B\r\n", $screen->parkBelow());
    }

    #[Test]
    public function parkingBeforeAnyFrameIsACarriageReturnAlone(): void
    {
        self::assertSame("\r", (new InlineScreen(new VirtualTerminal(10, 24)))->parkBelow());
    }

    #[Test]
    public function afterTheTerminalNarrowsTheRowsItsRewrappingAddedAreErased(): void
    {
        $terminal = new VirtualTerminal(10, 24);
        $screen = new InlineScreen($terminal);
        $screen->flush(self::frameOf('abcdefgh', 'xy'));
        $terminal->clearOutput();

        $narrow = new Frame(4, 2);
        $narrow->putString(0, 0, 'ok');
        $narrow->putString(0, 1, 'go');
        $screen->flush($narrow);

        self::assertStringStartsWith(Ansi::SYNC_START . "\r\x1b[1C\x1b[J\rok\x1b[K\r\ngo\x1b[K\x1b[1A\r", $terminal->output());
    }

    #[Test]
    public function afterTheTerminalWidensWhatIsBelowTheRegionIsErasedBecauseRejoinedLinesAboveCanLiftIt(): void
    {
        $terminal = new VirtualTerminal(4, 24);
        $screen = new InlineScreen($terminal);
        $narrow = new Frame(4, 1);
        $narrow->putString(0, 0, 'ok');
        $screen->flush($narrow);
        $terminal->clearOutput();

        $screen->flush(self::frameOf('ok'));

        self::assertStringStartsWith(Ansi::SYNC_START . "\r\x1b[1C\x1b[J\rok\x1b[K", $terminal->output());
    }

    #[Test]
    public function printedLinesReplaceTheRegionAndTheNextFrameStartsBelowThem(): void
    {
        $terminal = new VirtualTerminal(10, 24);
        $screen = new InlineScreen($terminal);
        $screen->flush(self::frameOf('one', 'two'));
        $terminal->clearOutput();

        $screen->printAbove(['✓ done'], 10);
        $printed = $terminal->output();
        $terminal->clearOutput();
        $screen->flush(self::frameOf('next'));

        self::assertSame(Ansi::SYNC_START . "\r\x1b[1C\x1b[J\r✓ done\r\n" . Ansi::SYNC_END, $printed);
        self::assertStringStartsWith(Ansi::SYNC_START . "\rnext", $terminal->output());
    }

    #[Test]
    public function printedLinesAreSplitMadePlainAndCutToTheWidthSoTheTerminalNeverWrapsThem(): void
    {
        $terminal = new VirtualTerminal(10, 24);

        (new InlineScreen($terminal))->printAbove(["a\nb", "\x1b[31mred", 'abcdefghijkl'], 10);

        self::assertStringContainsString("a\r\nb\r\nred\r\nabcdefghij\r\n", $terminal->output());
    }

    private static function frameOf(string ...$lines): Frame
    {
        $frame = new Frame(10, \count($lines));
        foreach ($lines as $row => $line) {
            $frame->putString(0, $row, $line);
        }

        return $frame;
    }
}
