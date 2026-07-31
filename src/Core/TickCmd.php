<?php

declare(strict_types=1);

namespace Elmphant\Tui\Core;

use InvalidArgumentException;

/**
 * @internal
 */
final readonly class TickCmd extends Cmd
{
    public function __construct(
        public float $interval,
        public ?EffectId $id = null,
    ) {
        if ($interval <= 0) {
            throw new InvalidArgumentException("Ticker interval must be positive, got {$interval}");
        }
    }
}
