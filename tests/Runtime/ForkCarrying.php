<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use Closure;
use Elmphant\Tui\Core\Message;

final readonly class ForkCarrying implements Message
{
    public function __construct(public Closure $callback)
    {
    }
}
