<?php

declare(strict_types=1);

namespace Elmphant\Tui\Rendering\Context;

use Closure;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Frame;
use LogicException;

/**
 * Popups queued while the tree draws, painted after it and unclipped, and the
 * places they landed so input can route over them.
 */
final class FloatQueue
{
    private ?Frame $rootFrame = null;
    private bool $rootPassRan = false;

    /**
     * @var list<array{Floating, Rect}>
     */
    private array $queued = [];

    /**
     * @var list<FloatPlacement>
     */
    private array $pendingPlacements = [];

    /**
     * @var list<FloatPlacement>
     */
    private array $placements = [];

    /**
     * Drops staged placements that were never committed, so a frame without a
     * root pass cannot publish an old popup again.
     */
    public function begin(): void
    {
        $this->pendingPlacements = [];
        $this->rootPassRan = false;
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

        $this->queued[] = [$popup, $anchor];
    }

    /**
     * @return list<array{Floating, Rect}>
     */
    public function queued(): array
    {
        return $this->queued;
    }

    /**
     * Draws the tree, then every float it queued on top. A nested pass would paint
     * before the surrounding tree finishes; a second pass in one frame would draw
     * its tree over the first pass's popup while that popup's placement still
     * claims the input.
     *
     * @param Closure(): void $drawTree
     */
    public function drawOver(Frame $frame, Closure $drawTree, RenderContext $context): void
    {
        if ($this->rootFrame !== null) {
            throw new LogicException('A root pass cannot be nested inside another root pass.');
        }

        if ($this->rootPassRan) {
            throw new LogicException('A root pass may run only once per frame; compose a multi-part screen under one root.');
        }

        $this->rootPassRan = true;
        $this->queued = [];
        $this->rootFrame = $frame;

        try {
            $drawTree();
            $this->pendingPlacements = $this->draw($frame, $context);
        } finally {
            $this->queued = [];
            $this->rootFrame = null;
        }
    }

    /**
     * For a model that paints its own screen instead of drawing a tree in a root pass.
     */
    public function drainOnto(Frame $frame, RenderContext $context): void
    {
        if ($this->rootPassRan || $this->queued === []) {
            return;
        }

        $this->rootPassRan = true;
        $this->rootFrame = $frame;

        try {
            $this->pendingPlacements = $this->draw($frame, $context);
        } finally {
            $this->queued = [];
            $this->rootFrame = null;
        }
    }

    /**
     * Called once the frame is on the screen, so input never routes over a frame
     * the runtime could not show.
     */
    public function commit(): void
    {
        if ($this->rootFrame !== null) {
            throw new LogicException('Float placements cannot be committed inside a root pass; the runtime commits once the frame is on the screen.');
        }

        $this->placements = $this->pendingPlacements;
    }

    /**
     * @return list<FloatPlacement>
     */
    public function placements(): array
    {
        return $this->placements;
    }

    /**
     * @return list<FloatPlacement>
     */
    private function draw(Frame $frame, RenderContext $context): array
    {
        $recorded = [];

        // A float may queue one of its own; three rounds is deeper than any
        // sensible chain and stops a cycle from spinning.
        for ($iteration = 0; $iteration < 3 && $this->queued !== []; ++$iteration) {
            $pending = $this->queued;
            $this->queued = [];

            foreach ($pending as [$popup, $anchor]) {
                $placement = $popup->placement($anchor, $frame->area());

                if ($placement->width < 1 || $placement->height < 1) {
                    continue;
                }

                $popup->render($frame, $placement, $context);

                if ($popup instanceof PointerFloating) {
                    $recorded[] = new FloatPlacement($popup, $anchor, $placement);
                }
            }
        }

        return $recorded;
    }
}
