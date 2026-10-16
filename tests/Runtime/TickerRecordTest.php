<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use Elmphant\Tui\Runtime\TickerRecord;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TickerRecordTest extends TestCase
{
    /**
     * Counting from the moment it happened to fire keeps whatever lateness the loop
     * had and adds to it every time, so a clock built on it runs slow for ever. A
     * stall longer than the interval skips the periods nobody saw instead of firing a
     * burst to catch up.
     */
    #[Test]
    public function theNextDeadlineFollowsTheLastOneRatherThanTheClock(): void
    {
        $ticker = new TickerRecord(fireAt: 1.0, interval: 0.1);

        self::assertSame(1.1, $ticker->rescheduled(1.005)->fireAt, 'lateness is not added to the period');
        self::assertGreaterThan(2.0, $ticker->rescheduled(2.0)->fireAt, 'a stall does not queue a burst');
    }

    #[Test]
    public function aStallResumesOnTheFirstDeadlineStillAhead(): void
    {
        $ticker = new TickerRecord(fireAt: 1.0, interval: 0.25);

        self::assertSame(1.75, $ticker->rescheduled(1.7)->fireAt);
        self::assertSame(1.5, $ticker->rescheduled(1.25)->fireAt, 'a deadline due this very moment counts as missed');
    }
}
