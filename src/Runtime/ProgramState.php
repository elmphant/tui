<?php

declare(strict_types=1);

namespace Elmphant\Tui\Runtime;

/**
 * @internal
 */
enum ProgramState
{
    case New;
    case Running;
    case Stopping;
    case Stopped;
}
