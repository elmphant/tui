<?php

declare(strict_types=1);

namespace Elmphant\Tui\Message;

enum ErrorSource: string
{
    case Update = 'update';
    case Render = 'render';
    case Command = 'command';
    case Call = 'call';
    case Timer = 'timer';
    case Listen = 'listen';
    case Cancel = 'cancel';
    case Process = 'process';
    case Http = 'http';
    case Fiber = 'fiber';
    case Fork = 'fork';
}
