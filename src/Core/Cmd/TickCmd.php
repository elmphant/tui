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
        // NAN fails every comparison, so it walks past a positivity guard and
        // then reads as due on every pass of the loop; INF schedules a tick the
        // program can never reach.
        if (!is_finite($interval) || $interval <= 0) {
            throw new InvalidArgumentException('Ticker interval must be a positive number of seconds, got ' . var_export($interval, true));
        }
    }

    public function effectId(): ?EffectId
    {
        return $this->id;
    }
}
