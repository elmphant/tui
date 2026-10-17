<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Widget\ClipsItsChildren;
use Elmphant\Tui\Widget\Plan\LayoutPlan;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ClipsEachChildTest extends TestCase
{
    #[Test]
    public function aPinnedChildOutsideTheScrollingRegionIsNotClippedByIt(): void
    {
        $scrolled = Text::new('long');
        $foot = Text::new('foot');

        $plan = LayoutPlan::resolve(new ScrollingWithAFoot($scrolled, $foot), Rect::fromSize(10, 6));

        self::assertEquals(new Rect(0, 0, 10, 4), $plan->visibleRects()[$scrolled], 'the scrolled child stops where the foot begins');
        self::assertEquals(new Rect(0, 4, 10, 2), $plan->visibleRects()[$foot], 'the foot is not cut away');
    }
}

final readonly class ScrollingWithAFoot implements ClipsItsChildren, Widget
{
    public function __construct(private Widget $scrolled, private Widget $foot)
    {
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::flex());
    }

    public function children(): iterable
    {
        return [$this->scrolled, $this->foot];
    }

    public function placedChildren(Rect $area): iterable
    {
        return [
            [$this->scrolled, new Rect($area->x, $area->y, $area->width, 8)],
            [$this->foot, new Rect($area->x, $area->bottom() - 2, $area->width, 2)],
        ];
    }

    public function viewport(Rect $area, Widget $child): Rect
    {
        return $child === $this->foot
            ? new Rect($area->x, $area->bottom() - 2, $area->width, 2)
            : new Rect($area->x, $area->y, $area->width, $area->height - 2);
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
    }
}
