<?php

declare(strict_types=1);

namespace Elmphant\Tui\Rendering\Context;

final class FrameClock
{
    private float $now = 0.0;
    private ?float $repaintDelay = null;

    public function begin(float $now): void
    {
        $this->now = $now;
        $this->repaintDelay = null;
    }

    public function now(): float
    {
        return $this->now;
    }

    /**
     * The smallest delay asked for during a frame wins. Without a request the
     * runtime only repaints on state changes.
     */
    public function requestRepaint(float $delay): void
    {
        if (!is_finite($delay)) {
            return;
        }

        $delay = max(0.0, $delay);
        $this->repaintDelay = $this->repaintDelay === null
            ? $delay
            : min($this->repaintDelay, $delay);
    }

    public function repaintDelay(): ?float
    {
        return $this->repaintDelay;
    }
}
