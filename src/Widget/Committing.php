<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget;

use Elmphant\Tui\Message\Message;

/**
 * Content that finishes its choice, so the popup holding it closes.
 *
 * Asked, never stored: a remembered flag needs someone to reset it, survives
 * into the next opening, and cannot represent re-picking what is already
 * chosen. The binding alone is not enough — an empty list still maps enter to
 * SELECT, so implementations also require an option to exist.
 */
interface Committing
{
    public function commits(Message $message): bool;
}
