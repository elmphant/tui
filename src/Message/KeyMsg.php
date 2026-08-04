<?php

declare(strict_types=1);

namespace Elmphant\Tui\Message;

use Elmphant\Tui\Layout\Rect;

final readonly class KeyMsg implements Message
{
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
        return new self($this->key, $this->ctrl, $this->alt, $this->shift, $this->eventType, $screen);
    }
}
