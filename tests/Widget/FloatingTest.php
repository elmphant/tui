<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Widget\Floating;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FloatingTest extends TestCase
{
    #[Test]
    public function aFloatingWidgetPlacesItselfAgainstAnAnchorAndAViewport(): void
    {
        $floating = new class implements Floating {
            public function placement(Rect $anchor, Rect $viewport): Rect
            {
                return new Rect($anchor->x, $anchor->bottom(), $anchor->width, 3);
            }

            public function constraint(): Constraint
            {
                return Constraint::flex();
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
            }
        };

        $placement = $floating->placement(new Rect(4, 6, 20, 1), Rect::fromSize(80, 24));

        self::assertSame([4, 7, 20, 3], [
            $placement->x,
            $placement->y,
            $placement->width,
            $placement->height,
        ]);
    }
}
