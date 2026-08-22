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

final class ScreenCursorTest extends TestCase
{
    #[Test]
    public function flushEmitsCursorShapeAndShowsCursor(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $screen = new Screen($terminal);
        $frame = new Frame(80, 24);
        $frame->putString(0, 0, 'Hello');
        $frame->setCursorPosition(5, 0, CursorShape::BlinkingBar);
        $screen->flush($frame);

        $output = $terminal->output();
        $this->assertStringContainsString(Ansi::cursorShape(CursorShape::BlinkingBar), $output);
        $this->assertStringContainsString(Ansi::SHOW_CURSOR, $output);
    }

    #[Test]
    public function flushHidesCursorWhenNoCursorSet(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $screen = new Screen($terminal);
        $frame = new Frame(80, 24);
        $frame->putString(0, 0, 'Hello');
        $screen->flush($frame);

        $output = $terminal->output();
        $this->assertStringContainsString(Ansi::HIDE_CURSOR, $output);
        $this->assertStringNotContainsString(Ansi::SHOW_CURSOR, $output);
    }
}
