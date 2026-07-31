<?php

declare(strict_types=1);

namespace Elmphant\Tui\Layout;

use Closure;
use Elmphant\Tui\Widget\Widget;
use SplObjectStorage;
use WeakMap;

/**
 * Memoizes resolved widget rects per owner instance and screen area.
 *
 * Sound only because owners (App models) are immutable and view() is a pure
 * function of the owner — the same instance and area always resolve to the
 * same layout. Entries die with their owner via WeakMap.
 *
 * @internal
 */
final class LayoutSnapshot
{
    /**
     * @var WeakMap<object, array{Rect, LayoutPlan}>|null
     */
    private static ?WeakMap $entries = null;

    /**
     * @param Closure(): Widget $view
     *
     * @return SplObjectStorage<Widget, Rect>
     */
    public static function rectsFor(object $owner, Rect $area, Closure $view): SplObjectStorage
    {
        return self::planFor($owner, $area, $view)->rects();
    }

    /**
     * @param Closure(): Widget $view
     */
    public static function planFor(object $owner, Rect $area, Closure $view): LayoutPlan
    {
        $entries = self::$entries ??= new WeakMap();

        $entry = $entries[$owner] ?? null;
        if ($entry !== null && $entry[0]->equals($area)) {
            return $entry[1];
        }

        $plan = LayoutPlan::resolve($view(), $area);
        $entries->offsetSet($owner, [$area, $plan]);

        return $plan;
    }

    public static function existingPlanFor(object $owner, Rect $area): ?LayoutPlan
    {
        $entry = self::$entries[$owner] ?? null;

        return $entry !== null && $entry[0]->equals($area)
            ? $entry[1]
            : null;
    }
}
