<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Stan\Fixtures;

use Elmphant\Tui\Layout\Constraint;

final readonly class AxisOwningValue
{
    public function __construct(private Constraint $width)
    {
    }

    public function width(): Constraint
    {
        return $this->width;
    }
}
