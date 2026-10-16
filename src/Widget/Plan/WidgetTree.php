<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Plan;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Widget\Widget;
use SplObjectStorage;

/**
 * @internal layout traversal implementation used by App routing
 */
final class WidgetTree
{
    /**
     * @return SplObjectStorage<Widget, Rect>
     */
    public static function resolveRects(Widget $root, Rect $area): SplObjectStorage
    {
        return LayoutPlan::resolve($root, $area)->rects();
    }

    /**
     * @param list<Widget> $targets
     *
     * @return list<?Rect>
     */
    public static function findRects(Widget $root, Rect $area, array $targets): array
    {
        $map = self::resolveRects($root, $area);
        $result = [];

        foreach ($targets as $target) {
            $result[] = $map->offsetExists($target) ? $map[$target] : null;
        }

        return $result;
    }
}
