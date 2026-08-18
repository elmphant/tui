<?php

declare(strict_types=1);

namespace Elmphant\Tui\Rendering;

use Closure;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\FocusState;
use Elmphant\Tui\Input\ColorProfile;
use Elmphant\Tui\Layout\LayoutPlan;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Widget\Floating;
use Elmphant\Tui\Widget\PointerFloating;
use Elmphant\Tui\Widget\Widget;
use Elmphant\Tui\Widget\WidgetContainer;
use LogicException;

final class RenderContext
{
    private ?LayoutPlan $layoutPlan = null;
    private FocusState $focus;
    private int $focusDepth = 0;
    private int $componentDepth = 0;

    /**
     * @var array<string, string>
     */
    private array $focusedHints = [];
    private float $now = 0.0;
    private ?float $repaintDelay = null;
    private ?Frame $rootFrame = null;
    private bool $rootPassRan = false;

    /**
     * @var list<array{Floating, Rect}>
     */
    private array $floats = [];

    /**
     * @var list<FloatPlacement>
     */
    private array $pendingPlacements = [];

    /**
     * @var list<FloatPlacement>
     */
    private array $placements = [];

    public function __construct(
        public readonly Theme $theme,
        public readonly ColorProfile $colorProfile = ColorProfile::TrueColor,
    ) {
        $this->focus = new FocusState();
    }

    /**
     * Monotonic time of the frame being rendered, in seconds.
     *
     * Widgets derive animation phase from this instead of carrying
     * message-driven animation state.
     */
    public function now(): float
    {
        return $this->now;
    }

    /**
     * Ask the runtime to render another frame within $delay seconds.
     *
     * Animated widgets call this during render(); the smallest requested
     * delay wins. Without a request the runtime only repaints on state
     * changes.
     */
    public function requestRepaint(float $delay): void
    {
        $delay = max(0.0, $delay);
        $this->repaintDelay = $this->repaintDelay === null
            ? $delay
            : min($this->repaintDelay, $delay);
    }

    /**
     * @internal runtime entry point: stamps the frame time, resets repaint
     * requests, and drops staged placements that were never committed, so a
     * frame without renderRoot() cannot publish an old popup again
     */
    public function beginFrame(float $now): void
    {
        $this->now = $now;
        $this->repaintDelay = null;
        $this->pendingPlacements = [];
        $this->rootPassRan = false;
        $this->focus = new FocusState();
        $this->focusDepth = 0;
        $this->componentDepth = 0;
        $this->focusedHints = [];
    }

    /**
     * @internal installed once per frame by the tree that knows its components
     */
    public function focusOn(FocusState $focus): void
    {
        $this->focus = $focus;
        $this->focusDepth = 0;
        $this->componentDepth = 0;
    }

    /**
     * Whether the widget being rendered shows focus, given the flag it still
     * carries. Inside a bound component the tree's answer is the only one, so a
     * stale flag cannot paint a second ring. Outside one — an app that routes the
     * keyboard itself, a pane not yet bound, or a widget rendered alone in a
     * test — there is nothing to defer to and the flag answers.
     */
    public function showsFocus(bool $flagged): bool
    {
        return $this->hasFocus() || ($this->componentDepth === 0 && $flagged);
    }

    public function focusedComponent(): ?ComponentId
    {
        return $this->focus->focused();
    }

    /**
     * @internal resolved once per frame by the tree that knows its components
     *
     * @param array<string, string> $hints
     */
    public function provideFocusedHints(array $hints): void
    {
        $this->focusedHints = $hints;
    }

    /**
     * The keys whatever holds the keyboard answers, readable at any point in the
     * tree rather than only after the focused widget has drawn.
     *
     * @return array<string, string>
     */
    public function focusedHints(): array
    {
        return $this->focusedHints;
    }

    /**
     * Whether the keyboard is in the widget being rendered. A widget asks rather
     * than remembers, so it cannot disagree with where the keys are routed.
     */
    public function hasFocus(): bool
    {
        return $this->focusDepth > 0;
    }

    /**
     * Draw a part as the one the keyboard reaches. Production opens this through
     * Component; a test that renders one widget on its own says so directly.
     *
     * @param Closure(): void $render
     */
    public function withFocusHere(Closure $render): void
    {
        ++$this->focusDepth;

        try {
            $render();
        } finally {
            --$this->focusDepth;
        }
    }

    /**
     * Draw a part that must not show the focus its container holds — the list
     * beside the field, the pane that is not the active one.
     *
     * @param Closure(): void $render
     */
    public function withoutFocus(Closure $render): void
    {
        $held = $this->focusDepth;
        $this->focusDepth = 0;

        try {
            $render();
        } finally {
            $this->focusDepth = $held;
        }
    }

    /**
     * @internal opened by Component around the subtree the keyboard is in
     *
     * @param Closure(): void $render
     */
    public function withinComponent(ComponentId $id, Closure $render): void
    {
        $holdsFocus = $this->focus->focused()?->equals($id) ?? false;

        if ($holdsFocus) {
            ++$this->focusDepth;
        }
        ++$this->componentDepth;

        try {
            $render();
        } finally {
            --$this->componentDepth;
            if ($holdsFocus) {
                --$this->focusDepth;
            }
        }
    }

    /**
     * @internal smallest repaint delay requested during the current frame, if any
     */
    public function repaintDelay(): ?float
    {
        return $this->repaintDelay;
    }

    public function float(Frame $frame, Floating $popup, Rect $anchor): void
    {
        if ($this->rootFrame !== null && $frame !== $this->rootFrame) {
            return;
        }

        $intersection = $frame->clip()->intersect($anchor);
        if ($intersection->width < 1 || $intersection->height < 1) {
            return;
        }

        $this->floats[] = [$popup, $anchor];
    }

    /**
     * @internal drain order for the root render pass
     *
     * @return list<array{Floating, Rect}>
     */
    public function floats(): array
    {
        return $this->floats;
    }

    /**
     * @internal runtime entry point that installs one pre-resolved layout plan
     */
    public function renderWithPlan(Widget $root, Frame $frame, Rect $area, LayoutPlan $plan): void
    {
        $previous = $this->layoutPlan;
        $this->layoutPlan = $plan;

        try {
            $root->render($frame, $area, $this);
        } finally {
            $this->layoutPlan = $previous;
        }
    }

    /**
     * @internal renderRoot() cannot nest: a nested drain would paint before the
     * surrounding tree finishes, violating draw-last
     *
     * It also runs once per frame: a second pass would paint its tree over the
     * first pass's popup, while that popup's placement still claims the input
     */
    public function renderRoot(Widget $root, Frame $frame, Rect $area, ?LayoutPlan $plan = null): void
    {
        if ($this->rootFrame !== null) {
            throw new LogicException('renderRoot() cannot be nested inside another renderRoot() pass.');
        }

        if ($this->rootPassRan) {
            throw new LogicException('renderRoot() may run only once per frame; compose a multi-part screen under one root.');
        }

        $this->rootPassRan = true;
        $this->floats = [];
        $this->rootFrame = $frame;

        try {
            if ($plan === null) {
                $root->render($frame, $area, $this);
            } else {
                $this->renderWithPlan($root, $frame, $area, $plan);
            }

            $this->pendingPlacements = $this->drawFloats($frame);
        } finally {
            $this->floats = [];
            $this->rootFrame = null;
        }
    }

    /**
     * @internal runtime entry point for a model that paints its own screen
     *           instead of handing a widget tree to renderRoot()
     */
    public function drainFloatsFor(Frame $frame): void
    {
        if ($this->rootPassRan || $this->floats === []) {
            return;
        }

        $this->rootPassRan = true;
        $this->rootFrame = $frame;

        try {
            $this->pendingPlacements = $this->drawFloats($frame);
        } finally {
            $this->floats = [];
            $this->rootFrame = null;
        }
    }

    /**
     * @return list<FloatPlacement>
     */
    private function drawFloats(Frame $frame): array
    {
        /** @var list<FloatPlacement> $recorded */
        $recorded = [];

        // A float may queue one of its own; three rounds is deeper than any
        // sensible chain and stops a cycle from spinning.
        for ($iteration = 0; $iteration < 3 && $this->floats !== []; ++$iteration) {
            $pending = $this->floats;
            $this->floats = [];

            foreach ($pending as [$popup, $anchor]) {
                $placement = $popup->placement($anchor, $frame->area());

                if ($placement->width < 1 || $placement->height < 1) {
                    continue;
                }

                $popup->render($frame, $placement, $this);

                if ($popup instanceof PointerFloating) {
                    $recorded[] = new FloatPlacement($popup, $anchor, $placement);
                }
            }
        }

        return $recorded;
    }

    /**
     * @internal runtime entry point: called once the frame is on the screen, so
     * input never routes over a frame the runtime could not show
     */
    public function commitFloatPlacements(): void
    {
        if ($this->rootFrame !== null) {
            throw new LogicException('commitFloatPlacements() cannot run inside a root pass; the runtime commits once the frame is on the screen.');
        }

        $this->placements = $this->pendingPlacements;
    }

    /**
     * @internal the placements of the last committed frame
     *
     * @return list<FloatPlacement>
     */
    public function floatPlacements(): array
    {
        return $this->placements;
    }

    /**
     * @return iterable<array{Widget, Rect}>
     */
    public function childrenOf(WidgetContainer $container, Rect $area): iterable
    {
        return $this->plannedChildrenOf($container, $area)
            ?? $container->childWidgets($area);
    }

    /**
     * Returns positioned children only when geometry was already resolved.
     *
     * Unlike childrenOf(), this never invokes the container's fallback layout.
     * Large viewport-aware containers use it to avoid positioning off-screen
     * children during render-only passes.
     *
     * @return list<array{Widget, Rect}>|null
     */
    public function plannedChildrenOf(WidgetContainer $container, Rect $area): ?array
    {
        return $this->layoutPlan?->childrenOf($container, $area);
    }
}
