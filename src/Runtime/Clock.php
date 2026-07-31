<?php

declare(strict_types=1);

namespace Elmphant\Tui\Runtime;

interface Clock
{
    /**
     * Monotonic timestamp in seconds. Only differences are meaningful — the epoch is arbitrary.
     */
    public function now(): float;
}
