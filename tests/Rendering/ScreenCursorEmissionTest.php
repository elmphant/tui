<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Rendering;

use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Screen;
use Elmphant\Tui\Terminal\Ansi;
use Elmphant\Tui\Terminal\CursorShape;
use Elmphant\Tui\Testing\VirtualTerminal;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Cursor output must not turn an unchanged frame into a terminal write.
 */
final class ScreenCursorEmissionTest extends TestCase
{
    private VirtualTerminal $terminal;
    private Screen $screen;
    private Frame $frame;

    #[Test]
    public function anUnchangedFrameWritesNothingAtAll(): void
    {
        $this->paint('steady');
        $this->terminal->clearOutput();

        $this->paint('steady');
        $this->paint('steady');

        self::assertSame('', $this->terminal->output());
    }

    #[Test]
    public function theFirstFrameStillHidesTheCursor(): void
    {
        $this->paint('steady');

        self::assertStringContainsString(Ansi::HIDE_CURSOR, $this->terminal->output());
    }

    #[Test]
    public function showingTheCursorIsEmittedOnce(): void
    {
        $this->paint('steady');
        $this->terminal->clearOutput();

        $this->paintWithCursor('steady', 2, 1);
        $afterFirst = $this->terminal->output();
        $this->terminal->clearOutput();
        $this->paintWithCursor('steady', 2, 1);

        self::assertStringContainsString(Ansi::SHOW_CURSOR, $afterFirst);
        self::assertSame('', $this->terminal->output());
    }

    /**
     * Drawing moves the terminal cursor, so restore it after any cell writes.
     */
    #[Test]
    public function aFrameThatDrewSomethingPutsTheCursorBack(): void
    {
        $this->paintWithCursor('steady', 2, 1);
        $this->terminal->clearOutput();

        $this->paintWithCursor('changed', 2, 1);

        self::assertStringContainsString(Ansi::cursorTo(1, 2), $this->terminal->output());
    }

    #[Test]
    public function movingTheCursorIsEmittedAgain(): void
    {
        $this->paintWithCursor('steady', 2, 1);
        $this->terminal->clearOutput();

        $this->paintWithCursor('steady', 5, 1);

        self::assertStringContainsString(Ansi::cursorTo(1, 5), $this->terminal->output());
    }

    #[Test]
    public function hidingTheCursorAgainIsEmitted(): void
    {
        $this->paintWithCursor('steady', 2, 1);
        $this->terminal->clearOutput();

        $this->paint('steady');

        self::assertStringContainsString(Ansi::HIDE_CURSOR, $this->terminal->output());
    }

    #[Test]
    public function clearingTheScreenMakesTheNextFrameReassertTheCursor(): void
    {
        $this->paint('steady');
        $this->screen->forget();
        $this->terminal->clearOutput();

        $this->paint('steady');

        self::assertStringContainsString(Ansi::HIDE_CURSOR, $this->terminal->output());
    }

    #[Test]
    public function aCaretThatKeptItsShapeIsNotToldWhatShapeToBeAgain(): void
    {
        $this->paintWithCursor('one', 3, 0);
        $this->terminal->clearOutput();

        $this->paintWithCursor('two', 3, 0);

        self::assertStringNotContainsString(
            Ansi::cursorShape(CursorShape::BlinkingBar),
            $this->terminal->output(),
            'that sequence restarts the blink, so a repaint beside the caret would stop it ever blinking',
        );
        self::assertStringContainsString(
            Ansi::cursorTo(0, 3),
            $this->terminal->output(),
            'it still goes back where it belongs once the cells have been written',
        );
    }

    #[Test]
    public function aClearedScreenSaysWhatShapeTheCaretIsAgain(): void
    {
        $this->paintWithCursor('one', 3, 0);
        $this->screen->forget();
        $this->terminal->clearOutput();

        $this->paintWithCursor('one', 3, 0);

        self::assertStringContainsString(
            Ansi::cursorShape(CursorShape::BlinkingBar),
            $this->terminal->output(),
            'the screen was given up and whatever had it since may have left the caret another shape',
        );
    }

    #[Test]
    public function aFullRedrawShowsTheCaretItHid(): void
    {
        $this->paintWithCursor('one', 3, 0);
        $this->terminal->clearOutput();

        $this->screen->forceRedraw($this->frame);

        self::assertStringEndsWith(Ansi::SHOW_CURSOR . Ansi::SYNC_END, $this->terminal->output());
    }

    #[Test]
    public function aCaretAlreadyOnScreenIsNotToldToAppearAgain(): void
    {
        $this->paintWithCursor('one', 3, 0);
        $this->terminal->clearOutput();

        $this->paintWithCursor('two', 3, 0);

        self::assertStringNotContainsString(
            Ansi::SHOW_CURSOR,
            $this->terminal->output(),
            'showing a caret that is already showing is another sequence that disturbs its blink',
        );
    }

    protected function setUp(): void
    {
        $this->terminal = new VirtualTerminal(20, 3);
        $this->screen = new Screen($this->terminal);
        $this->frame = new Frame(20, 3);
    }

    private function paint(string $text): void
    {
        $this->frame->clear(0);
        $this->frame->putString(0, 0, $text);
        $this->screen->flush($this->frame);
    }

    private function paintWithCursor(string $text, int $x, int $y): void
    {
        $this->frame->clear(0);
        $this->frame->putString(0, 0, $text);
        $this->frame->setCursorPosition($x, $y, CursorShape::BlinkingBar);
        $this->screen->flush($this->frame);
    }
}
