<?php

declare(strict_types=1);

namespace Elmphant\Tui\Runtime;

use Closure;
use Revolt\EventLoop;

/**
 * @internal event-loop scheduling policy owned by Program
 */
final class AdaptiveTicker
{
    private const MIN_INTERVAL = 0.001;
    private const ACTIVE_INTERVAL = 0.01;
    private const HYSTERESIS_THRESHOLD = 0.0001;

    private ?string $callbackId = null;
    private ?float $currentInterval = null;

    /**
     * @param Closure(): void $onTick
     */
    public function __construct(private readonly Closure $onTick)
    {
    }

    public function refresh(?float $nextRenderDelay, ?float $nextTimerDelay, bool $processesActive, bool $queuePending = false): void
    {
        $interval = $this->computeInterval($nextRenderDelay, $nextTimerDelay, $processesActive, $queuePending);

        if ($interval === null) {
            $this->stop();

            return;
        }

        if ($this->currentInterval !== null && abs($interval - $this->currentInterval) < self::HYSTERESIS_THRESHOLD) {
            return;
        }

        $this->stop();
        $this->callbackId = EventLoop::repeat($interval, fn () => ($this->onTick)());
        $this->currentInterval = $interval;
    }

    public function stop(): void
    {
        if ($this->callbackId !== null) {
            EventLoop::cancel($this->callbackId);
            $this->callbackId = null;
            $this->currentInterval = null;
        }
    }

    public function computeInterval(?float $nextRenderDelay, ?float $nextTimerDelay, bool $processesActive, bool $queuePending = false): ?float
    {
        $intervals = [];

        if ($nextRenderDelay !== null) {
            $intervals[] = $nextRenderDelay;
        }

        if ($processesActive || $queuePending) {
            $intervals[] = self::ACTIVE_INTERVAL;
        }

        if ($nextTimerDelay !== null) {
            $intervals[] = $nextTimerDelay;
        }

        return $intervals === [] ? null : max(self::MIN_INTERVAL, min($intervals));
    }
}
