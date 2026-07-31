<?php

declare(strict_types=1);

namespace Elmphant\Tui\Message;

final readonly class PasteMsg implements Message
{
    public function __construct(
        public string $content,
    ) {
    }
}
