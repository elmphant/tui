<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Rendering;

use Elmphant\Tui\Rendering\CursorPosition;
use Elmphant\Tui\Rendering\CursorShape;
use Elmphant\Tui\Rendering\Frame;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CursorTest extends TestCase
{
    #[Test]
    public function decscusrValues(): void
    {
        $this->assertSame(1, CursorShape::BlinkingBlock->value);
        $this->assertSame(2, CursorShape::SteadyBlock->value);
        $this->assertSame(5, CursorShape::BlinkingBar->value);
        $this->assertSame(6, CursorShape::SteadyBar->value);
    }

    #[Test]
    public function setCursorPositionOnBuffer(): void
    {
        $frame = new Frame(80, 24);
        $frame->setCursorPosition(5, 3, CursorShape::BlinkingBar);
        $pos = $frame->cursorPosition;
        $this->assertInstanceOf(CursorPosition::class, $pos);
        $this->assertSame(3, $pos->row);
        $this->assertSame(5, $pos->col);
        $this->assertSame(CursorShape::BlinkingBar, $pos->shape);
    }

    #[Test]
    public function cursorPositionClearedOnBufferClear(): void
    {
        $frame = new Frame(80, 24);
        $frame->setCursorPosition(5, 3, CursorShape::BlinkingBar);
        $frame->clear();
        $this->assertNull($frame->cursorPosition);
    }
}
