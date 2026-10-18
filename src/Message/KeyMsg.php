<?php

declare(strict_types=1);

namespace Elmphant\Tui\Message;

use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Support\Cloneable;

final readonly class KeyMsg implements Message
{
    use Cloneable;

    /**
     * A string here is always text: a key name such as 'enter' is given as its Key.
     */
    public Key|string $key;

    public function __construct(
        Key|string $key,
        public bool $ctrl = false,
        public bool $alt = false,
        public bool $shift = false,
        public KeyEventType $eventType = KeyEventType::Press,
        public ?Rect $screen = null,
    ) {
        $this->key = is_string($key) ? Key::NAMES[strtolower($key)] ?? $key : $key;
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
