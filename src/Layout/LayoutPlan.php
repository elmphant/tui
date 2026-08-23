<?php

declare(strict_types=1);

namespace Elmphant\Tui\Layout;

use Elmphant\Tui\Widget\Dispatch\SingleMount;
use Elmphant\Tui\Widget\Widget;
use Elmphant\Tui\Widget\WidgetContainer;
use LogicException;
use SplObjectStorage;

/**
 * Immutable resolved geometry for one widget tree and terminal area.
 *
 * Besides widget rectangles, the plan retains each container's positioned
 * children. Rendering reuses the plan only when the App instance that routed
 * the message is also the instance being rendered (no-op updates, ticks);
 * a state-changing update produces a new App instance whose render resolves
 * fresh geometry.
 *
 * @internal
 */
final class LayoutPlan
{
    /**
     * @var SplObjectStorage<Widget, Rect>
     */
    private SplObjectStorage $rects;

    /**
     * A container object may be mounted more than once, so placements are
     * indexed by both object identity and its supplied area.
     *
     * @var SplObjectStorage<WidgetContainer, array<string, list<array{Widget, Rect}>>>
     */
    private SplObjectStorage $children;

    private function __construct()
    {
        $this->rects = new SplObjectStorage();
        $this->children = new SplObjectStorage();
    }

    public static function resolve(Widget $root, Rect $area): self
    {
        $plan = new self();
        $plan->walk($root, $area);

        return $plan;
    }

    /**
     * @return SplObjectStorage<Widget, Rect>
     */
    public function rects(): SplObjectStorage
    {
        return $this->rects;
    }

    /**
     * @return list<array{Widget, Rect}>|null
     */
    public function childrenOf(WidgetContainer $container, Rect $area): ?array
    {
        if (!$this->children->offsetExists($container)) {
            return null;
        }

        /** @var array<string, list<array{Widget, Rect}>> $byArea */
        $byArea = $this->children[$container];

        return $byArea[self::areaKey($area)] ?? null;
    }

    private function walk(Widget $widget, Rect $area): void
    {
        if ($widget instanceof SingleMount && $this->rects->offsetExists($widget)) {
            throw new LogicException(sprintf('Widget "%s" is mounted more than once in the same widget tree. Give each place its own instance: the rect is found by object identity, so a second mount would replace the first.', $widget->mountName()));
        }

        $this->rects[$widget] = $area;

        if (!$widget instanceof WidgetContainer) {
            return;
        }

        $placements = [];
        foreach ($widget->placedChildren($area) as [$child, $childArea]) {
            $placements[] = [$child, $childArea];
        }

        /** @var array<string, list<array{Widget, Rect}>> $byArea */
        $byArea = $this->children->offsetExists($widget)
            ? $this->children[$widget]
            : [];
        $byArea[self::areaKey($area)] = $placements;
        $this->children[$widget] = $byArea;

        foreach ($placements as [$child, $childArea]) {
            $this->walk($child, $childArea);
        }
    }

    private static function areaKey(Rect $area): string
    {
        return "{$area->x}:{$area->y}:{$area->width}:{$area->height}";
    }
}
