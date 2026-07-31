<?php

declare(strict_types=1);

namespace Elmphant\Tui\Runtime\Trace;

/**
 * Where a running program reports what it is doing.
 *
 * A TUI cannot print its own diagnostics: stdout is the display. Attach a
 * tracer to a Program to get the flow out of the process instead.
 */
interface Tracer
{
    /**
     * @param array<string, scalar|null> $fields
     */
    public function record(TraceEvent $event, array $fields): void;
}
