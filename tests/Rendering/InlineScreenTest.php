<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Rendering;

use Elmphant\Tui\Rendering\Attr;
use Elmphant\Tui\Rendering\Color;
use Elmphant\Tui\Rendering\CursorPosition;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\InlineScreen;
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
    public function theNextFrameGoesBackUpToTheFirstRowBeforeItPaints(): void
    {
        $terminal = new VirtualTerminal(10, 24);
        $screen = new InlineScreen($terminal);
        $screen->flush(self::frameOf('one', 'two', 'three'));
        $terminal->clearOutput();

        $screen->flush(self::frameOf('one', 'two', 'four'));

        self::assertStringStartsWith(Ansi::SYNC_START . "\x1b[2A\r", $terminal->output());
        self::assertSame(2, substr_count($terminal->output(), "\r\n"));
    }

    #[Test]
    public function aShorterFrameErasesTheRowsTheTallerOneLeftBelowIt(): void
    {
        $terminal = new VirtualTerminal(10, 24);
        $screen = new InlineScreen($terminal);
        $screen->flush(self::frameOf('one', 'two', 'three'));
        $terminal->clearOutput();

        $screen->flush(self::frameOf('one'));

        self::assertStringContainsString("one\x1b[K\r\n\x1b[J\x1b[1A", $terminal->output());
    }

    #[Test]
    public function aStyledCellCarriesItsStyleAndTheRowIsErasedInTheDefaultStyle(): void
    {
        $terminal = new VirtualTerminal(10, 24);
        $frame = new Frame(10, 1);
        $frame->putString(0, 0, 'ok', Color::ansi(1)->value, 0, Attr::BOLD);

        (new InlineScreen($terminal))->flush($frame);

        self::assertStringContainsString("\x1b[0;1;31;49mok\x1b[0m\x1b[K", $terminal->output());
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
    public function theVisibleCursorIsReachedFromTheLastRowAndTheNextFrameGoesUpFromIt(): void
    {
        $terminal = new VirtualTerminal(10, 24);
        $screen = new InlineScreen($terminal);
        $frame = self::frameOf('one', 'two', 'three');
        $frame->cursorPosition = new CursorPosition(1, 4, CursorShape::SteadyBar);

        $screen->flush($frame);
        $placed = $terminal->output();
        $terminal->clearOutput();
        $screen->flush(self::frameOf('one', 'two', 'three'));

        self::assertStringContainsString("three\x1b[K\x1b[1A\r\x1b[4C" . Ansi::cursorShape(CursorShape::SteadyBar) . Ansi::SHOW_CURSOR, $placed);
        self::assertStringStartsWith(Ansi::SYNC_START . "\x1b[1A\r", $terminal->output());
    }

    #[Test]
    public function parkingFromTheSecondRowOfAFiveRowRegionGoesDownThreeRowsToTheLineBelow(): void
    {
        $screen = new InlineScreen(new VirtualTerminal(10, 24));
        $frame = self::frameOf('1', '2', '3', '4', '5');
        $frame->cursorPosition = new CursorPosition(1, 3, CursorShape::SteadyBar);
        $screen->flush($frame);

        self::assertSame("\x1b[3B\r\n", $screen->parkBelow());
    }

    #[Test]
    public function parkingBeforeAnyFrameIsACarriageReturnAlone(): void
    {
        self::assertSame("\r", (new InlineScreen(new VirtualTerminal(10, 24)))->parkBelow());
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

        self::assertSame(Ansi::SYNC_START . "\x1b[1A\r\x1b[J✓ done\r\n" . Ansi::SYNC_END, $printed);
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
