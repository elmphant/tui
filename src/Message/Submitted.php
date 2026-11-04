<?php

declare(strict_types=1);

namespace Elmphant\Tui\Message;

use Elmphant\Tui\Core\Message;

/**
 * A field or form committed its value. Handed to the bind closure and its `on()` handlers in place of the key.
 */
final readonly class Submitted implements Message
{
    public function __construct(public string $value, public Message $trigger)
    {
    }
}
