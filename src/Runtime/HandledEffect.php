<?php

declare(strict_types=1);

namespace Elmphant\Tui\Runtime;

use Closure;
use Elmphant\Tui\Core\EffectId;

/**
 * @internal
 */
final class HandledEffect
{
    /**
     * Stopped or finished: either way, what it emits from now on belongs to nobody.
     */
    public bool $over = false;

    /**
     * @var Closure(): void|null
     */
    public ?Closure $stopper = null;

    public function __construct(public readonly ?EffectId $id = null)
    {
    }

    public function stop(): void
    {
        $this->over = true;
        if ($this->stopper !== null) {
            ($this->stopper)();
        }
    }
}
