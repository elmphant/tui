<?php

declare(strict_types=1);

namespace Elmphant\Tui\Rendering;

use Elmphant\Tui\Layout\LayoutPlan;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Widget\Floating;
use Elmphant\Tui\Widget\Widget;
use Elmphant\Tui\Widget\WidgetContainer;

final class RenderContext
{
    private ?Frame $scratch = null;
    private ?LayoutPlan $layoutPlan = null;
    private float $now = 0.0;
    private ?float $repaintDelay = null;

    /**
     * @var list<array{Floating, Rect}>
     */
    private array $floats = [];

    public function __construct(
        public readonly Theme $theme,
    ) {
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
     * @internal runtime entry point: stamps the frame time and resets repaint requests
     */
    public function beginFrame(float $now): void
    {
        $this->now = $now;
        $this->repaintDelay = null;
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
     * @internal
     */
    public function resetFloats(): void
    {
        $this->floats = [];
    }

    public function scratchBuffer(int $width, int $height): Frame
    {
        if ($this->scratch === null) {
            return $this->scratch = new Frame($width, $height);
        }

        if ($this->scratch->width < $width || $this->scratch->height < $height) {
            $this->scratch = new Frame(
                max($width, $this->scratch->width),
                max($height, $this->scratch->height),
            );
        } else {
            $this->scratch->clear();
        }

        return $this->scratch;
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

    public function renderRoot(Widget $root, Frame $frame, Rect $area, ?LayoutPlan $plan = null): void
    {
        $this->resetFloats();

        if ($plan === null) {
            $root->render($frame, $area, $this);
        } else {
            $this->renderWithPlan($root, $frame, $area, $plan);
        }

        $queued = $this->floats;
        $this->floats = [];

        foreach ($queued as [$popup, $anchor]) {
            $placement = $popup->placement($anchor, $frame->area());

            if ($placement->width < 1 || $placement->height < 1) {
                continue;
            }

            $popup->render($frame, $placement, $this);
        }
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
