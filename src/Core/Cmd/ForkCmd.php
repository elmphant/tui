<?php

declare(strict_types=1);

namespace Elmphant\Tui\Core\Cmd;

use Closure;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\EffectId;
use Elmphant\Tui\Core\NamesItsEffect;

/**
 * @internal
 */
final readonly class ForkCmd extends Cmd implements NamesItsEffect
{
    /**
     * @param Closure(Closure): mixed $work
     */
    public function __construct(
        public Closure $work,
        public ?EffectId $id = null,
    ) {
    }

    public function effectId(): ?EffectId
    {
        return $this->id;
    }
}
