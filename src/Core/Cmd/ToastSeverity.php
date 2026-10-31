<?php

declare(strict_types=1);

namespace Elmphant\Tui\Core\Cmd;

enum ToastSeverity
{
    case Info;
    case Success;
    case Warning;
    case Error;
}
