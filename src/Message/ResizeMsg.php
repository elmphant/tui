<?php

declare(strict_types=1);

namespace Elmphant\Tui\Message;

final readonly class ResizeMsg implements Message
{
    public function __construct(
        public int $width,
        public int $height,
    ) {
    }
}
