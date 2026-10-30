<?php

declare(strict_types=1);

namespace Elmphant\Tui\Runtime\Trace;

/**
 * Send diagnostics to a tracer because stdout belongs to the terminal display.
 */
interface Tracer
{
    /**
     * @param array<string, scalar|list<string>|null> $fields
     */
    public function record(TraceEvent $event, array $fields): void;
}
