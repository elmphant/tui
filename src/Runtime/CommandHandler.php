<?php

declare(strict_types=1);

namespace Elmphant\Tui\Runtime;

use Closure;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Message;

/**
 * Runs a command the runtime has no dispatcher for, so an application can add
 * an effect of its own — a query on an event-loop-aware database client, a
 * websocket, anything that finishes later — without the runtime knowing what it
 * is. The built-in commands are answered before any handler is asked, so a
 * handler never sees one of those however greedily it answers `handles()`.
 */
interface CommandHandler
{
    public function handles(Cmd $command): bool;

    /**
     * Start the effect. Whatever it produces goes through `$emit`, which puts the
     * message on the queue the model reads — under the mapping of a
     * `Cmd::map()`ped command, so a child component's effect stays in its own
     * namespace — and wakes a loop that was waiting for input.
     *
     * The closure answered, if any, is how the runtime stops the effect again:
     * on `Cmd::cancel()`, when another command takes the same
     * `NamesItsEffect::effectId()`, and when the program shuts down. An effect
     * that has nothing to stop answers null.
     *
     * An effect that ends on its own calls `$finished`: the runtime lets go of
     * it, so its stop closure is never called and anything it emits afterwards
     * is dropped.
     *
     * @param Closure(Message):void $emit
     * @param Closure(): void       $finished
     *
     * @return Closure(): void|null
     */
    public function start(Cmd $command, Closure $emit, Closure $finished): ?Closure;
}
