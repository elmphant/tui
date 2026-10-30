<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Context\RenderContext;

final class PlacedChildren
{
    /**
     * @return iterable<array{Widget, Rect}>
     */
    public static function of(WidgetContainer $container, Rect $area, RenderContext $context): iterable
    {
        $planned = $context->plannedChildrenOf($container, $area);
        if ($planned === null) {
            return $container->placedChildren($area);
        }

        /** @var list<array{Widget, Rect}> $planned */
        return $planned;
    }
}
