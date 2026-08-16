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
    public function beginFrame(float $now, FocusState $focus = new FocusState()): void
    {
        $this->now = $now;
        $this->repaintDelay = null;
        $this->pendingPlacements = [];
        $this->rootPassRan = false;
        $this->focus = $focus;
        $this->focusDepth = 0;
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

        try {
            $render();
        } finally {
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

            /** @var list<FloatPlacement> $recorded */
            $recorded = [];

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

            $this->pendingPlacements = $recorded;
        } finally {
            $this->floats = [];
            $this->rootFrame = null;
        }
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
