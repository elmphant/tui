<?php

declare(strict_types=1);

namespace Elmphant\Tui\Core;

/**
 * Opt-in hook for models that need a last word before the program exits.
 *
 * The Program runtime calls `onShutdown()` on the *final* model once the event
 * loop has ended — after a quit key, `Cmd::quit()`, or SIGINT/SIGTERM/SIGHUP —
 * and before the terminal is restored. Startup failures skip it: a model that
 * never ran has no state worth keeping.
 *
 * There is no loop left to deliver messages, so the hook returns nothing and
 * any work it does must be synchronous. Exceptions propagate out of
 * `Program::start()`, but the terminal is restored either way.
 */
interface ShutdownAware
{
    public function onShutdown(): void;
}
