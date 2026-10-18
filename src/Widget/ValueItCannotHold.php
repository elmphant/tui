<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget;

use InvalidArgumentException;

final class ValueItCannotHold extends InvalidArgumentException
{
    public static function given(HoldsAValue $widget, mixed $value): self
    {
        return new self(sprintf('%s cannot hold a value of type %s', $widget::class, get_debug_type($value)));
    }
}
