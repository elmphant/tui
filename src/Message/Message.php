<?php

declare(strict_types=1);

namespace Elmphant\Tui\Message;

/**
 * Marker interface for every message that may flow through the runtime.
 *
 * Application-defined messages sent with Cmd::message() must implement this
 * interface too.
 */
interface Message
{
}
