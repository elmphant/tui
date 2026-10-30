<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget;

use Elmphant\Tui\Core\Message;

/**
 * Derive commitment from the message and current options, never from a stored flag or binding alone.
 * A commit closes a single-choice popup and reaches the bound host even when the selection is unchanged.
 */
interface Committing
{
    public function commits(Message $message): bool;
}
