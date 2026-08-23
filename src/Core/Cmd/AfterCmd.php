<?php

declare(strict_types=1);

namespace Elmphant\Tui\Core\Cmd;

use Closure;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\EffectId;
use Elmphant\Tui\Message\Message;

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
    }
}
