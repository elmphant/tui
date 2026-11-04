<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget;

use Elmphant\Tui\Core\Message;

/**
 * Derive the outcome from the message and current options, never from a stored flag or binding alone.
 * An outcome closes a single-choice popup and reaches the bound host even when the widget is unchanged.
 */
interface Committing
{
    public function outcomeOf(Message $message): ?Message;
}
