<?php

declare(strict_types=1);

namespace Elmphant\Tui\Application;

use Elmphant\Tui\Core\Message;

/**
 * @internal what Mount::toHost() sends, on its way out of the mount
 */
final readonly class TellsTheHost implements Message
{
    public function __construct(
        public Message $message,
    ) {
    }
}
