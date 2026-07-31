<?php

declare(strict_types=1);

namespace Elmphant\Tui\Message;

enum ProcessStream
{
    case Stdout;
    case Stderr;
}
