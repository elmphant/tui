<?php

declare(strict_types=1);

namespace Elmphant\Tui\Message;

use Elmphant\Tui\Core\EffectId;

trait ComesFromAnEffect
{
    public function isFrom(EffectId|string $effect): bool
    {
        return $this->effectId !== null
            && $this->effectId->value === ($effect instanceof EffectId ? $effect->value : $effect);
    }
}
