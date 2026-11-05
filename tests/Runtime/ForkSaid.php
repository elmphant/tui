<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use Elmphant\Tui\Core\Message;

final readonly class ForkSaid implements Message
{
    public function __construct(public string $what, public bool $last = true)
    {
    }
}
