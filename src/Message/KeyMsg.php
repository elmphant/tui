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

    /**
     * @param list<self> $preceding the keys of a chord this one completes
     */
    public function __construct(
        Key|string $key,
        public bool $ctrl = false,
        public bool $alt = false,
        public bool $shift = false,
        public KeyEventType $eventType = KeyEventType::Press,
        public ?Rect $screen = null,
        public array $preceding = [],
    ) {
        $this->key = is_string($key) ? Key::NAMES[strtolower($key)] ?? $key : $key;
    }

    /**
     * Release events accompany presses; handling both would perform an action twice.
     */
    public function isRelease(): bool
    {
        return $this->eventType === KeyEventType::Release;
    }

    public function isText(): bool
    {
        return is_string($this->key) && !$this->ctrl && !$this->alt && $this->preceding === [];
    }

    public function withScreen(Rect $screen): self
    {
        return $this->cloneWith(screen: $screen);
    }

    /**
     * @param list<self> $keys
     */
    public function withPreceding(array $keys): self
    {
        return $this->cloneWith(preceding: $keys);
    }
}
