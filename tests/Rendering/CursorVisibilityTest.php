<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Rendering;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\CursorShape;
use Elmphant\Tui\Rendering\Frame;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CursorVisibilityTest extends TestCase
{
    #[Test]
    public function aClaimOutsideTheClipIsIgnored(): void
    {
        $frame = new Frame(20, 3);
        $frame->pushClip(new Rect(0, 0, 20, 1));

        $frame->setCursorPosition(5, 2, CursorShape::BlinkingBar);

        self::assertNull($frame->cursorPosition);
    }
}
