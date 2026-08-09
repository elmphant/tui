<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget;

/**
 * A widget whose rect is found by object identity, so it may be mounted once.
 */
interface SingleMount
{
    public function mountName(): string;
}
