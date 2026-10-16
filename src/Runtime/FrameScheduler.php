<?php

declare(strict_types=1);

namespace Elmphant\Tui\Runtime;

/**
 * @internal when Program draws: on a change or an animation's repaint, never faster than the frame rate
 */
final class FrameScheduler
{
    private readonly float $budget;
    private bool $dirty = true;
    private float $lastFrameAt = 0.0;
    private ?float $repaintAt = null;

    public function __construct(float $framesPerSecond = 60.0)
    {
        $this->budget = 1.0 / max(1.0, $framesPerSecond);
    }

    public function budget(): float
    {
        return $this->budget;
    }

    public function markDirty(): void
    {
        $this->dirty = true;
    }

    public function isDirty(): bool
    {
        return $this->dirty;
    }

    public function wantsFrame(float $now): bool
    {
        return $this->dirty || ($this->repaintAt !== null && $now >= $this->repaintAt);
    }

    public function isDue(float $now): bool
    {
        return $this->wantsFrame($now) && $now >= $this->lastFrameAt + $this->budget;
    }

    public function heldFor(float $now): float
    {
        return $this->lastFrameAt + $this->budget - $now;
    }

    public function drawn(float $at, ?float $repaintDelay): void
    {
        $this->dirty = false;
        $this->lastFrameAt = $at;
        $this->repaintAt = $repaintDelay === null ? null : $at + $repaintDelay;
    }

    public function dropped(float $at): void
    {
        $this->dirty = true;
        $this->lastFrameAt = $at;
        $this->repaintAt = null;
    }

    public function nextDelay(float $now): ?float
    {
        $budgetReadyAt = $this->lastFrameAt + $this->budget;

        $nextFrameAt = $this->dirty ? $budgetReadyAt : null;
        if ($this->repaintAt !== null) {
            $repaintReadyAt = max($this->repaintAt, $budgetReadyAt);
            $nextFrameAt = $nextFrameAt === null ? $repaintReadyAt : min($nextFrameAt, $repaintReadyAt);
        }

        return $nextFrameAt === null ? null : max(0.0, $nextFrameAt - $now);
    }
}
