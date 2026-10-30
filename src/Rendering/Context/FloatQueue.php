<?php

declare(strict_types=1);

namespace Elmphant\Tui\Rendering\Context;

use Closure;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Frame;
use LogicException;

/**
 * Queue popups to draw after the tree, without clipping; retain their placements for input routing.
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
     * Discard uncommitted placements so a frame without a root pass cannot reuse an old popup.
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
     * Allow one root pass per frame: nested or repeated passes would disagree with the popup placements used for input.
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
     * Commit placements only after output succeeds, so input matches the visible frame.
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

        // Limit nested floats to three rounds to stop cycles.
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
