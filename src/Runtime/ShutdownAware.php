<?php

declare(strict_types=1);

namespace Elmphant\Tui\Runtime;

/**
 * Called on the final model after the loop ends, before restoring the terminal; skipped if startup fails.
 * Work must be synchronous; exceptions propagate, but the terminal is still restored.
 */
interface ShutdownAware
{
    public function onShutdown(): void;
}
