<?php

declare(strict_types=1);

namespace Elmphant\Tui\Application;

use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\Message;

/**
 * @internal what a mounted model asked for, on its way back to it
 */
final readonly class MountedMsg implements Message
{
    public function __construct(
        public ComponentId $mount,
        public Message $message,
    ) {
    }
}
