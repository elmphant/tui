<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Rendering;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\FloatQueue;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Widget\Popup\Floating;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FloatQueueTest extends TestCase
{
    #[Test]
    public function aFloatIsRecordedWithTheAnchorItWasQueuedAgainst(): void
    {
        $floats = new FloatQueue();
        $frame = new Frame(80, 24);
        $popup = self::popup();

        $floats->float($frame, $popup, new Rect(4, 6, 20, 1));

        self::assertEquals([[$popup, new Rect(4, 6, 20, 1)]], $floats->queued());
    }

    #[Test]
    public function anAnchorScrolledOutsideTheClipInForceIsDropped(): void
    {
        $floats = new FloatQueue();
        $frame = new Frame(80, 24);
        $frame->pushClip(new Rect(0, 10, 80, 5));

        $floats->float($frame, self::popup(), new Rect(4, 2, 20, 1));

        self::assertSame([], $floats->queued(), 'A field scrolled out of its pane has no popup');
    }

    #[Test]
    public function anAnchorIntersectingTheClipByASingleCellIsStillQueued(): void
    {
        $floats = new FloatQueue();
        $frame = new Frame(80, 24);
        $frame->pushClip(new Rect(0, 0, 5, 5));

        $floats->float($frame, self::popup(), new Rect(4, 4, 20, 20));

        self::assertNotSame([], $floats->queued(), 'A one-cell intersection is still an intersection, not nothing');
    }

    private static function popup(): Floating
    {
        return new class implements Floating {
            public function placement(Rect $anchor, Rect $viewport): Rect
            {
                return $anchor;
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
            }
        };
    }
}
