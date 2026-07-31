<?php

declare(strict_types=1);

namespace Elmphant\Tui\Message;

final readonly class KeyMsg implements Message
{
    public function __construct(
        public Key|string $key,
        public bool $ctrl = false,
        public bool $alt = false,
        public bool $shift = false,
        public KeyEventType $eventType = KeyEventType::Press,
    ) {
    }
}
