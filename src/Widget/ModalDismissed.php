<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget;

use Elmphant\Tui\Message\Message;

final readonly class ModalDismissed implements Message
{
    public function __construct(
        public string $modalTitle,
    ) {
    }
}
