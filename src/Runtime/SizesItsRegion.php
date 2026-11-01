<?php

declare(strict_types=1);

namespace Elmphant\Tui\Runtime;

interface SizesItsRegion
{
    public function wantedHeight(int $width): int;
}
