<?php

declare(strict_types=1);

namespace Elmphant\Tui\Core\Cmd;

use Closure;
use Elmphant\Tui\Core\Cmd;

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
