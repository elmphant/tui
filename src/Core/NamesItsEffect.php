<?php

declare(strict_types=1);

namespace Elmphant\Tui\Core;

/**
 * Named effects can be cancelled or replaced, including those run by application CommandHandlers.
 */
interface NamesItsEffect
{
    public function effectId(): ?EffectId;
}
