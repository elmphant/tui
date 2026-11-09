<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Focus;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Widget\Interactive;

/**
 * A null answer holds it back from the handlers.
 */
final readonly class ChildAnswer
{
    public function __construct(
        public Interactive $container,
        public ?Message $answered,
        public ?Cmd $command = null,
    ) {
    }
}
