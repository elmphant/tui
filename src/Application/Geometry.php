<?php

declare(strict_types=1);

namespace Elmphant\Tui\Application;

use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Widget\Plan\LayoutPlan;
use Elmphant\Tui\Widget\Widget;
use SplObjectStorage;

final readonly class Geometry
{
    private function __construct(
        private LayoutPlan $plan,
        private ComponentRegistry $components,
    ) {
    }

    public static function of(Widget $root, Rect $area): self
    {
        return new self(LayoutPlan::resolve($root, $area), ComponentRegistry::fromWidget($root));
    }

    /**
     * @internal the plan and registry App already holds for its own view
     */
    public static function from(LayoutPlan $plan, ComponentRegistry $components): self
    {
        return new self($plan, $components);
    }

    /**
     * Find components by ID and plain widgets by object identity.
     */
    public function rectOf(ComponentId|string|Widget $target): ?Rect
    {
        return $this->rectIn($this->plan->rects(), $target);
    }

    /**
     * Only the part a pane has not scrolled away, so a hidden field answers an empty rect.
     */
    public function visibleRectOf(ComponentId|string|Widget $target): ?Rect
    {
        return $this->rectIn($this->plan->visibleRects(), $target);
    }

    /**
     * Hit-test the innermost visible component; scrolled-out panes cannot receive clicks.
     */
    public function componentAt(int $x, int $y): ?ComponentId
    {
        $found = null;
        $visible = $this->plan->visibleRects();

        // Pre-order: a component is listed before everything inside it.
        foreach ($this->components->all() as $component) {
            if ($this->placed($component, $visible)?->contains($x, $y) === true) {
                $found = $component->id;
            }
        }

        return $found;
    }

    /**
     * @param SplObjectStorage<Widget, Rect> $rects
     */
    private function rectIn(SplObjectStorage $rects, ComponentId|string|Widget $target): ?Rect
    {
        if ($target instanceof Widget) {
            return $rects->offsetExists($target) ? $rects[$target] : null;
        }

        $component = $this->components->get(\is_string($target) ? ComponentId::named($target) : $target);

        return $component === null ? null : $this->placed($component, $rects);
    }

    /**
     * Grafted components are absent from the tree; look up their widgets instead.
     *
     * @param Component<\Elmphant\Tui\Widget\Interactive> $component
     * @param SplObjectStorage<Widget, Rect>              $rects
     */
    private function placed(Component $component, SplObjectStorage $rects): ?Rect
    {
        foreach ([$component, $component->widget] as $placed) {
            if ($rects->offsetExists($placed)) {
                return $rects[$placed];
            }
        }

        return null;
    }
}
