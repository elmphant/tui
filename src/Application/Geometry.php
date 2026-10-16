<?php

declare(strict_types=1);

namespace Elmphant\Tui\Application;

use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Widget\Plan\LayoutPlan;
use Elmphant\Tui\Widget\Widget;
use SplObjectStorage;

/**
 * Where the components of a tree landed, read from the plan that draws it.
 */
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

    public function rectOf(ComponentId|string $id): ?Rect
    {
        $component = $this->components->get(\is_string($id) ? ComponentId::named($id) : $id);

        return $component === null ? null : $this->placed($component, $this->plan->rects());
    }

    /**
     * The innermost component the pointer can reach at that cell; a pane scrolled
     * out of view cannot be under it.
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
     * A component a pane grafts is not itself in the tree; its widget is.
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
