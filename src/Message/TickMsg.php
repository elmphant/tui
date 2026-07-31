<?php

declare(strict_types=1);

namespace Elmphant\Tui\Message;

final readonly class TickMsg implements Message
{
    public function __construct(public float $timestamp)
    {
    }
}
