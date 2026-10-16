<?php

declare(strict_types=1);

namespace Elmphant\Tui\Message;

use Elmphant\Tui\Core\Message;

enum QuitMsg implements Message
{
    case Quit;
}
