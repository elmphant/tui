<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;

/**
 * For a widget that wraps one child and is sized, placed and drawn as that child:
 * implement wrapped() and override only what differs.
 *
 * @phpstan-require-implements WidgetContainer
 */
trait DecoratesOneChild
{
    public function sizing(): Sizing
    {
        return $this->wrapped()->sizing();
    }

    /**
     * @return list<Widget>
     */
    public function children(): iterable
    {
        return [$this->wrapped()];
    }

    /**
     * @return list<array{0: Widget, 1: Rect}>
     */
    public function placedChildren(Rect $area): iterable
    {
        return [[$this->wrapped(), $area]];
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        $this->wrapped()->render($frame, $area, $context);
    }

    abstract protected function wrapped(): Widget;
}
