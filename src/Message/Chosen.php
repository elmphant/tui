<?php

declare(strict_types=1);

namespace Elmphant\Tui\Message;

use Elmphant\Tui\Core\Message;

/**
 * The reader committed to an item, row or option with Enter or a click. Handed on in place of the trigger.
 */
final readonly class Chosen implements Message
{
    public function __construct(public object|string $choice, public Message $trigger)
    {
    }
}
