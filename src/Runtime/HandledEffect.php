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
     * Ignore emissions after the effect stops or finishes.
     */
    public bool $over = false;

    public bool $starting = true;

    public bool $emittedWhileStarting = false;

    /**
     * @var Closure(): void|null
     */
    public ?Closure $stopper = null;

    public function __construct(
        public readonly ?EffectId $id = null,
        public readonly ?string $scope = null,
    ) {
    }

    public function stop(): void
    {
        $this->over = true;
        if ($this->stopper !== null) {
            ($this->stopper)();
        }
    }
}
