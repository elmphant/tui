<?php

declare(strict_types=1);

namespace Elmphant\Tui\Runtime;

use Closure;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Message;

/**
 * Handle application-defined commands; built-in commands are dispatched before custom handlers are consulted.
 */
interface CommandHandler
{
    public function handles(Cmd $command): bool;

    /**
     * Emit results through $emit to preserve command mapping and wake the loop; return a cleanup closure, or null if none is needed.
     * Call $finished on natural completion to release the effect without cleanup and discard later emissions.
     *
     * @param Closure(Message):void $emit
     * @param Closure(): void       $finished
     *
     * @return Closure(): void|null
     */
    public function start(Cmd $command, Closure $emit, Closure $finished): ?Closure;
}
