<?php

declare(strict_types=1);

namespace Elmphant\Tui\Runtime;

/**
 * @internal what the loop is watching on the program's behalf, so a watcher can
 *           be cancelled by name and every one of them let go at shutdown
 */
enum EventLoopWatcher
{
    case Resize;
    case Interrupt;
    case Terminate;
    case Hangup;
    case Suspend;
    case Resumed;
    case Input;
    case EscapeFlush;
}
