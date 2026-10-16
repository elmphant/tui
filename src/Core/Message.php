<?php

declare(strict_types=1);

namespace Elmphant\Tui\Core;

/**
 * Marker interface for every message that may flow through the runtime.
 *
 * Messages a host defines and sends with Cmd::message() must implement this
 * interface too.
 */
interface Message
{
}
