<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use Elmphant\Tui\Runtime\FrameScheduler;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FrameSchedulerTest extends TestCase
{
    #[Test]
    public function theFirstFrameIsDueAtOnce(): void
    {
        $frames = new FrameScheduler(10.0);

        self::assertTrue($frames->isDue(0.1));
        self::assertSame(0.0, $frames->nextDelay(0.1));
    }

    #[Test]
    public function aDrawnScreenWantsNoFrameUntilSomethingChanges(): void
    {
        $frames = new FrameScheduler(10.0);
        $frames->drawn(1.0, null);

        self::assertFalse($frames->wantsFrame(5.0));
        self::assertNull($frames->nextDelay(5.0));

        $frames->markDirty();

        self::assertTrue($frames->isDue(5.0));
    }

    #[Test]
    public function aChangeWithinTheBudgetWaitsForIt(): void
    {
        $frames = new FrameScheduler(10.0);
        $frames->drawn(1.0, null);
        $frames->markDirty();

        self::assertTrue($frames->wantsFrame(1.04));
        self::assertFalse($frames->isDue(1.04));
        self::assertEqualsWithDelta(0.06, $frames->heldFor(1.04), 1e-9);
        self::assertEqualsWithDelta(0.06, $frames->nextDelay(1.04), 1e-9);
        self::assertTrue($frames->isDue(1.1));
    }

    #[Test]
    public function anAnimationAsksForTheFrameItsDelayNames(): void
    {
        $frames = new FrameScheduler(10.0);
        $frames->drawn(1.0, 0.5);

        self::assertFalse($frames->wantsFrame(1.4));
        self::assertEqualsWithDelta(0.1, $frames->nextDelay(1.4), 1e-9);
        self::assertTrue($frames->isDue(1.5));
    }

    #[Test]
    public function anAnimationNeverOutrunsTheBudget(): void
    {
        $frames = new FrameScheduler(10.0);
        $frames->drawn(1.0, 0.01);

        self::assertEqualsWithDelta(0.1, $frames->nextDelay(1.0), 1e-9);
    }

    #[Test]
    public function aDroppedFrameStaysWantedButStopsTheAnimation(): void
    {
        $frames = new FrameScheduler(10.0);
        $frames->drawn(1.0, 0.5);
        $frames->markDirty();

        $frames->dropped(2.0);

        self::assertTrue($frames->isDirty());
        self::assertFalse($frames->isDue(2.05));
        self::assertTrue($frames->isDue(2.1));
    }

    #[Test]
    public function theRateNeverFallsBelowOneFramePerSecond(): void
    {
        self::assertSame(1.0, (new FrameScheduler(0.5))->budget());
        self::assertSame(1.0 / 30.0, (new FrameScheduler(30.0))->budget());
    }
}
