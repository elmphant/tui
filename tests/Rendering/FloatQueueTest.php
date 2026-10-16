<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Rendering;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Context\Floating;
use Elmphant\Tui\Rendering\Context\FloatQueue;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Theme;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

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

    #[Test]
    public function aFloatQueuedAfterTheRootPassIsNotDrainedIntoTheSameFrame(): void
    {
        $floats = new FloatQueue();
        $frame = new Frame(10, 1);
        $context = RenderContext::unfocused(Theme::dark());
        $floats->drawOver($frame, static function (): void {
        }, $context);

        $floats->float($frame, self::marking('P'), new Rect(0, 0, 1, 1));
        $floats->drainOnto($frame, $context);

        self::assertSame(' ', $frame->characters[0]);
    }

    #[Test]
    public function aFrameIsDrainedOnlyOnce(): void
    {
        $floats = new FloatQueue();
        $frame = new Frame(10, 1);
        $context = RenderContext::unfocused(Theme::dark());
        $floats->float($frame, self::marking('A'), new Rect(0, 0, 1, 1));
        $floats->drainOnto($frame, $context);

        $floats->float($frame, self::marking('B'), new Rect(1, 0, 1, 1));
        $floats->drainOnto($frame, $context);

        self::assertSame('A ', $frame->characters[0] . $frame->characters[1]);
    }

    #[Test]
    public function aFloatThatThrowsWhileDrainedLeavesNoPassOpen(): void
    {
        $floats = new FloatQueue();
        $frame = new Frame(10, 1);
        $floats->float($frame, self::throwing(), new Rect(0, 0, 1, 1));

        try {
            $floats->drainOnto($frame, RenderContext::unfocused(Theme::dark()));
        } catch (RuntimeException) {
        }
        $floats->commit();

        self::assertSame([], $floats->queued());
    }

    private static function marking(string $mark): Floating
    {
        return new class($mark) implements Floating {
            public function __construct(private readonly string $mark)
            {
            }

            public function placement(Rect $anchor, Rect $viewport): Rect
            {
                return $anchor;
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
                $frame->putString($area->x, $area->y, $this->mark);
            }
        };
    }

    private static function throwing(): Floating
    {
        return new class implements Floating {
            public function placement(Rect $anchor, Rect $viewport): Rect
            {
                return $anchor;
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
                throw new RuntimeException('the popup failed to draw');
            }
        };
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
