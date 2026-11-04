<?php

declare(strict_types=1);

namespace Elmphant\Tui\Message;

use Elmphant\Tui\Core\Message;

/**
 * A button was pressed with a key or a click. Handed on in place of the trigger.
 */
final readonly class Pressed implements Message
{
    public function __construct(public Message $trigger)
    {
    }
}
