<?php

declare(strict_types=1);

namespace Elmphant\Tui\Core\Cmd;

use Closure;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Message;

/**
 * @internal
 */
final readonly class MappedCmd extends Cmd
{
    /**
     * @param Closure(Message):Message $wrap
     */
    public function __construct(
        public Cmd $inner,
        public Closure $wrap,
    ) {
    }
}
