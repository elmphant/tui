<?php

declare(strict_types=1);

namespace Elmphant\Tui\Runtime;

enum ProgramState
{
    case New;
    case Running;
    case Stopping;
    case Stopped;
}
