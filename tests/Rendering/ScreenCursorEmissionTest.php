<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Rendering;

use Elmphant\Tui\Rendering\Ansi;
use Elmphant\Tui\Rendering\CursorShape;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Screen;
use Elmphant\Tui\Testing\VirtualTerminal;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The diff renderer emits nothing for a screen that did not change, so the
 * cursor must not undo that by re-asserting its state every frame.
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

        self::assertSame('', $this->terminal->getOutput());
    }

    #[Test]
    public function theFirstFrameStillHidesTheCursor(): void
    {
        $this->paint('steady');

        self::assertStringContainsString(Ansi::HIDE_CURSOR, $this->terminal->getOutput());
    }

    #[Test]
    public function showingTheCursorIsEmittedOnce(): void
    {
        $this->paint('steady');
        $this->terminal->clearOutput();

        $this->paintWithCursor('steady', 2, 1);
        $afterFirst = $this->terminal->getOutput();
        $this->terminal->clearOutput();
        $this->paintWithCursor('steady', 2, 1);

        self::assertStringContainsString(Ansi::SHOW_CURSOR, $afterFirst);
        self::assertSame('', $this->terminal->getOutput());
    }

    /**
     * Writing cells walks the terminal's own cursor to the end of the last run,
     * so a frame that drew anything has to put it back.
     */
    #[Test]
    public function aFrameThatDrewSomethingPutsTheCursorBack(): void
    {
        $this->paintWithCursor('steady', 2, 1);
        $this->terminal->clearOutput();

        $this->paintWithCursor('changed', 2, 1);

        self::assertStringContainsString(Ansi::cursorTo(1, 2), $this->terminal->getOutput());
    }

    #[Test]
    public function movingTheCursorIsEmittedAgain(): void
    {
        $this->paintWithCursor('steady', 2, 1);
        $this->terminal->clearOutput();

        $this->paintWithCursor('steady', 5, 1);

        self::assertStringContainsString(Ansi::SHOW_CURSOR, $this->terminal->getOutput());
    }

    #[Test]
    public function hidingTheCursorAgainIsEmitted(): void
    {
        $this->paintWithCursor('steady', 2, 1);
        $this->terminal->clearOutput();

        $this->paint('steady');

        self::assertStringContainsString(Ansi::HIDE_CURSOR, $this->terminal->getOutput());
    }

    #[Test]
    public function clearingTheScreenMakesTheNextFrameReassertTheCursor(): void
    {
        $this->paint('steady');
        $this->screen->clear();
        $this->terminal->clearOutput();

        $this->paint('steady');

        self::assertStringContainsString(Ansi::HIDE_CURSOR, $this->terminal->getOutput());
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
