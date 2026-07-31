<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget;

/**
 * The width a widget would like before anything wraps or truncates it.
 */
interface MeasureWidth
{
    public function measureWidth(): int;
}
