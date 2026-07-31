<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use Elmphant\Tui\Runtime\AdaptiveTicker;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class AdaptiveTickerTest extends TestCase
{
    #[Test]
    public function renderDelayUsesExactDeadlineInsteadOfActivePolling(): void
    {
        $ticker = new AdaptiveTicker(fn () => null);
        $interval = $ticker->computeInterval(
            nextRenderDelay: 1.0,
            nextTimerDelay: null,
            processesActive: false,
        );
        $this->assertSame(1.0, $interval);
    }

    #[Test]
    public function processesActiveReturnsActiveInterval(): void
    {
        $ticker = new AdaptiveTicker(fn () => null);
        $interval = $ticker->computeInterval(
            nextRenderDelay: null,
            nextTimerDelay: null,
            processesActive: true,
        );
        $this->assertSame(0.01, $interval);
    }

    #[Test]
    public function timerDelayUsesExactValue(): void
    {
        $ticker = new AdaptiveTicker(fn () => null);
        $interval = $ticker->computeInterval(
            nextRenderDelay: null,
            nextTimerDelay: 0.15,
            processesActive: false,
        );
        $this->assertSame(0.15, $interval);
    }

    #[Test]
    public function multipleSignalsTakesMinimum(): void
    {
        $ticker = new AdaptiveTicker(fn () => null);
        $interval = $ticker->computeInterval(
            nextRenderDelay: 0.5,
            nextTimerDelay: 0.5,
            processesActive: true,
        );
        $this->assertSame(0.01, $interval);
    }

    #[Test]
    public function queuePendingReturnsActiveInterval(): void
    {
        $ticker = new AdaptiveTicker(fn () => null);
        $interval = $ticker->computeInterval(
            nextRenderDelay: null,
            nextTimerDelay: null,
            processesActive: false,
            queuePending: true,
        );
        $this->assertSame(0.01, $interval);
    }

    #[Test]
    public function noSignalsReturnsNull(): void
    {
        $ticker = new AdaptiveTicker(fn () => null);
        $interval = $ticker->computeInterval(
            nextRenderDelay: null,
            nextTimerDelay: null,
            processesActive: false,
        );
        $this->assertNull($interval);
    }

    #[Test]
    public function minimumIntervalClampedToOneMs(): void
    {
        $ticker = new AdaptiveTicker(fn () => null);
        $interval = $ticker->computeInterval(
            nextRenderDelay: null,
            nextTimerDelay: 0.0001,
            processesActive: false,
        );
        $this->assertSame(0.001, $interval);
    }

    #[Test]
    public function refreshCallsOnTickWhenRenderDirty(): void
    {
        $ticked = false;
        $ticker = new AdaptiveTicker(function () use (&$ticked) { $ticked = true; });
        $ticker->refresh(nextRenderDelay: 0.001, nextTimerDelay: null, processesActive: false);

        \Revolt\EventLoop::delay(0.02, fn () => \Revolt\EventLoop::getDriver()->stop());
        \Revolt\EventLoop::run();

        $ticker->stop();
        $this->assertTrue($ticked);
    }

    #[Test]
    public function refreshWithNoSignalsDoesNotTick(): void
    {
        $ticked = false;
        $ticker = new AdaptiveTicker(function () use (&$ticked) { $ticked = true; });
        $ticker->refresh(nextRenderDelay: null, nextTimerDelay: null, processesActive: false);

        \Revolt\EventLoop::delay(0.02, fn () => \Revolt\EventLoop::getDriver()->stop());
        \Revolt\EventLoop::run();

        $ticker->stop();
        $this->assertFalse($ticked);
    }

    #[Test]
    public function refreshWithQueuePendingSchedulesCallback(): void
    {
        $ticker = new AdaptiveTicker(fn () => null);
        $ticker->refresh(nextRenderDelay: null, nextTimerDelay: null, processesActive: false, queuePending: true);

        $callbackId = (new ReflectionProperty($ticker, 'callbackId'))->getValue($ticker);
        $ticker->stop();

        $this->assertNotNull($callbackId, 'A pending queued message must keep the tick callback scheduled');
    }

    #[Test]
    public function refreshSkipsRescheduleWhenIntervalUnchanged(): void
    {
        $ticker = new AdaptiveTicker(fn () => null);

        $ticker->refresh(nextRenderDelay: 0.01, nextTimerDelay: null, processesActive: false);
        $callbackId = (new ReflectionProperty($ticker, 'callbackId'))->getValue($ticker);
        $this->assertNotNull($callbackId);

        $ticker->refresh(nextRenderDelay: 0.01, nextTimerDelay: null, processesActive: false);
        $callbackIdAfter = (new ReflectionProperty($ticker, 'callbackId'))->getValue($ticker);

        $this->assertSame($callbackId, $callbackIdAfter, 'Same interval should reuse existing callback');

        $ticker->stop();
    }

    #[Test]
    public function refreshReschedulesWhenIntervalChanges(): void
    {
        $ticker = new AdaptiveTicker(fn () => null);

        $ticker->refresh(nextRenderDelay: null, nextTimerDelay: 0.5, processesActive: false);
        $callbackId = (new ReflectionProperty($ticker, 'callbackId'))->getValue($ticker);

        $ticker->refresh(nextRenderDelay: 0.01, nextTimerDelay: null, processesActive: false);
        $callbackIdAfter = (new ReflectionProperty($ticker, 'callbackId'))->getValue($ticker);

        $this->assertNotSame($callbackId, $callbackIdAfter, 'Different interval must create new callback');

        $ticker->stop();
    }
}
