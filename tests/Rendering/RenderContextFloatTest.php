<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Rendering;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Widget\Floating;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RenderContextFloatTest extends TestCase
{
    #[Test]
    public function aFloatIsRecordedWithTheAnchorItWasQueuedAgainst(): void
    {
        $context = new RenderContext(Theme::dark());
        $frame = new Frame(80, 24);
        $popup = self::popup();

        $context->float($frame, $popup, new Rect(4, 6, 20, 1));

        self::assertEquals([[$popup, new Rect(4, 6, 20, 1)]], $context->floats());
    }

    #[Test]
    public function anAnchorScrolledOutsideTheClipInForceIsDropped(): void
    {
        $context = new RenderContext(Theme::dark());
        $frame = new Frame(80, 24);
        $frame->pushClip(new Rect(0, 10, 80, 5));

        $context->float($frame, self::popup(), new Rect(4, 2, 20, 1));

        self::assertSame([], $context->floats(), 'A field scrolled out of its pane has no popup');
    }

    #[Test]
    public function anAnchorIntersectingTheClipByASingleCellIsStillQueued(): void
    {
        $context = new RenderContext(Theme::dark());
        $frame = new Frame(80, 24);
        $frame->pushClip(new Rect(0, 0, 5, 5));

        $context->float($frame, self::popup(), new Rect(4, 4, 20, 20));

        self::assertNotSame([], $context->floats(), 'A one-cell intersection is still an intersection, not nothing');
    }

    private static function popup(): Floating
    {
        return new class implements Floating {
            public function placement(Rect $anchor, Rect $viewport): Rect
            {
                return $anchor;
            }

            public function sizing(): Sizing
            {
                return Sizing::of(Constraint::flex(), Constraint::flex());
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
            }
        };
    }
}
