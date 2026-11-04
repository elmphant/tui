<?php

declare(strict_types=1);

namespace Elmphant\Tui\Message;

use Elmphant\Tui\Core\Message;

/**
 * A modal closed itself on Escape or a click outside. Handed on in place of the trigger.
 */
final readonly class Dismissed implements Message
{
    public function __construct(public Message $trigger)
    {
    }
}
