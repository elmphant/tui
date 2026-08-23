<?php

declare(strict_types=1);

namespace Elmphant\Tui\Core;

use Closure;

/**
 * @internal
 */
final readonly class CallCmd extends Cmd
{
    /**
     * @param Closure(): mixed $callback
     */
    public function __construct(
        public Closure $callback,
    ) {
    }
}
