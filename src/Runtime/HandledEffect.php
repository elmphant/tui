<?php

declare(strict_types=1);

namespace Elmphant\Tui\Runtime;

use Closure;
use Elmphant\Tui\Core\EffectId;

/**
 * @internal
 */
final readonly class HandledEffect
{
    /**
     * @param Closure(): void $stop
     */
    public function __construct(
        public Closure $stop,
        public ?EffectId $id = null,
    ) {
    }
}
