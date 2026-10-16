<?php

declare(strict_types=1);

namespace Elmphant\Tui\Message;

use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Support\Cloneable;

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

    /**
     * A terminal reporting key event types sends one of these for every press,
     * so acting on it acts on the keystroke twice.
     */
    public function isRelease(): bool
    {
        return $this->eventType === KeyEventType::Release;
    }

    public function withScreen(Rect $screen): self
    {
        return $this->cloneWith(screen: $screen);
    }
}
