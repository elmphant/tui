<?php

declare(strict_types=1);

namespace Elmphant\Tui\Message;

final readonly class FocusMsg implements Message
{
    public function __construct(public bool $focused)
    {
    }
}
