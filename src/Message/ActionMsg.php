<?php

declare(strict_types=1);

namespace Elmphant\Tui\Message;

use Elmphant\Tui\Core\Message;

/**
 * A key `Component::withAction()` declared, handed to that component's write-back before its handler runs.
 */
final readonly class ActionMsg implements Message
{
    public function __construct(public string $action, public KeyMsg $key)
    {
    }
}
