<?php

declare(strict_types=1);

namespace Elmphant\Tui\Core\Cmd;

use Closure;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\EffectId;
use Elmphant\Tui\Message\Message;
use InvalidArgumentException;

/**
 * @internal
 */
final readonly class AfterCmd extends Cmd
{
    /**
     * @param Closure(): (Message|Cmd|null) $then
     */
    public function __construct(
        public float $delay,
        public Closure $then,
        public ?EffectId $id = null,
    ) {
        // NAN fails every comparison, so a timer holding one is never due and
        // never cancelled by being due; INF names a moment no program reaches.
        if (!is_finite($delay) || $delay < 0) {
            throw new InvalidArgumentException("Delay must be a non-negative number of seconds, got {$delay}");
        }
    }
}
