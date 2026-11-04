<?php

declare(strict_types=1);

namespace Elmphant\Tui\Message;

use Elmphant\Tui\Core\Message;

/**
 * An action of `Component::withActions()`, handed to that component's bind closure.
 */
final readonly class ActionMsg implements Message
{
    public function __construct(public string $action, public KeyMsg $key)
    {
    }
}
