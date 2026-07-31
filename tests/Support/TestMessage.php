<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Support;

use Elmphant\Tui\Message\Message;

final readonly class TestMessage implements Message
{
    public function __construct(public string $value)
    {
    }
}
