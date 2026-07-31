<?php

declare(strict_types=1);

namespace Elmphant\Tui\Runtime;

/**
 * @internal
 */
enum TerminalSessionState
{
    case New;
    case Active;
    case Suspended;
    case Closed;
}
