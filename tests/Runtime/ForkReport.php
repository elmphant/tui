<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use Elmphant\Tui\Core\Message;

final readonly class ForkReport implements Message
{
    /**
     * @param list<self> $parts
     */
    public function __construct(public string $name, public array $parts, public int|float|null $size)
    {
    }
}
