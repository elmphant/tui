<?php

declare(strict_types=1);

namespace Elmphant\Tui\Core;

use Closure;
use Elmphant\Tui\Message\Message;

/**
 * @internal
 */
final readonly class MappedCmd extends Cmd
{
    /**
     * @param Closure(Message): Message $wrap
     */
    public function __construct(
        public Cmd $inner,
        public Closure $wrap,
    ) {
    }
}
