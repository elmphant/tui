<?php

declare(strict_types=1);

namespace Elmphant\Tui\Message;

use Elmphant\Tui\Core\Cloneable;
use Elmphant\Tui\Layout\Rect;

final readonly class KeyMsg implements Message
{
    use Cloneable;

    public function __construct(
        public Key|string $key,
        public bool $ctrl = false,
        public bool $alt = false,
        public bool $shift = false,
        public KeyEventType $eventType = KeyEventType::Press,
        public ?Rect $screen = null,
    ) {
    }

    public function withScreen(Rect $screen): self
    {
        return $this->cloneWith(screen: $screen);
    }
}
