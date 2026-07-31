<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Stan\Fixtures;

use Elmphant\Tui\Core\Cloneable;

final readonly class SelfCloneCaller
{
    use Cloneable;

    public function __construct(public int $value = 0)
    {
    }

    public function increment(): self
    {
        return $this->cloneWith(value: $this->value + 1);
    }
}
