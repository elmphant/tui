<?php

declare(strict_types=1);

namespace Elmphant\Tui\Runtime;

/**
 * @internal
 */
final class MonotonicClock implements Clock
{
    public function now(): float
    {
        return hrtime(true) / 1_000_000_000;
    }
}
