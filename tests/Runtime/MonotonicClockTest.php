<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use Elmphant\Tui\Runtime\Clock;
use Elmphant\Tui\Runtime\MonotonicClock;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MonotonicClockTest extends TestCase
{
    #[Test]
    public function implementsClock(): void
    {
        self::assertInstanceOf(Clock::class, new MonotonicClock());
    }

    #[Test]
    public function nowIsMonotonicallyNonDecreasing(): void
    {
        $clock = new MonotonicClock();
        $first = $clock->now();
        $second = $clock->now();

        self::assertGreaterThanOrEqual($first, $second);
    }

    #[Test]
    public function nowAdvancesWithRealTime(): void
    {
        $clock = new MonotonicClock();
        $start = $clock->now();
        usleep(15000);

        self::assertGreaterThan($start + 0.005, $clock->now());
    }
}
