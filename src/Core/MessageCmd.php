<?php

declare(strict_types=1);

namespace Elmphant\Tui\Core;

use Elmphant\Tui\Message\Message;

/**
 * @internal
 */
final readonly class MessageCmd extends Cmd
{
    public function __construct(
        public Message $message,
    ) {
    }
}
