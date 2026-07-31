<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget;

interface MeasureHeight
{
    public function measureHeight(int $width): int;
}
