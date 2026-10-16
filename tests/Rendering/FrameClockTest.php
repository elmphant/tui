<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Rendering;

use Elmphant\Tui\Rendering\FrameClock;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FrameClockTest extends TestCase
{
    #[Test]
    public function frameTimeDefaultsToZero(): void
    {
        self::assertSame(0.0, (new FrameClock())->now());
    }

    #[Test]
    public function beginningAFrameSetsItsTime(): void
    {
        $clock = new FrameClock();

        $clock->begin(2.5);

        self::assertSame(2.5, $clock->now());
    }

    #[Test]
    public function repaintDelayIsNullWithoutRequests(): void
    {
        self::assertNull((new FrameClock())->repaintDelay());
    }

    #[Test]
    public function aRepaintRequestRecordsTheDelay(): void
    {
        $clock = new FrameClock();

        $clock->requestRepaint(0.08);

        self::assertSame(0.08, $clock->repaintDelay());
    }

    #[Test]
    public function theSmallestRequestedDelayWins(): void
    {
        $clock = new FrameClock();

        $clock->requestRepaint(0.08);
        $clock->requestRepaint(0.05);
        $clock->requestRepaint(0.2);

        self::assertSame(0.05, $clock->repaintDelay());
    }

    /**
     * A delay is turned into the moment the next frame is due, and a moment
     * nothing can compare stops the animation clock for the whole program —
     * not just for the widget that asked.
     */
    #[Test]
    public function aDelayThatNamesNoMomentIsIgnored(): void
    {
        $clock = new FrameClock();

        $clock->requestRepaint(NAN);

        self::assertNull($clock->repaintDelay(), 'a frame due at no moment is a frame that never comes');
    }

    #[Test]
    public function aNegativeDelayIsClampedToZero(): void
    {
        $clock = new FrameClock();

        $clock->requestRepaint(-1.0);

        self::assertSame(0.0, $clock->repaintDelay());
    }

    #[Test]
    public function beginningAFrameClearsPendingRepaintRequests(): void
    {
        $clock = new FrameClock();
        $clock->requestRepaint(0.08);

        $clock->begin(1.0);

        self::assertNull($clock->repaintDelay());
    }
}
