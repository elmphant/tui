<?php

declare(strict_types=1);

namespace Elmphant\Tui\Core\Cmd;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\EffectId;
use Elmphant\Tui\Core\NamesItsEffect;
use InvalidArgumentException;

/**
 * @internal
 */
final readonly class TickCmd extends Cmd implements NamesItsEffect
{
    public function __construct(
        public float $interval,
        public ?EffectId $id = null,
    ) {
        // Reject NaN, which bypasses positivity checks, and infinity, which never becomes due.
        if (!is_finite($interval) || $interval <= 0) {
            throw new InvalidArgumentException('Ticker interval must be a positive number of seconds, got ' . var_export($interval, true));
        }
    }

    public function effectId(): ?EffectId
    {
        return $this->id;
    }
}
