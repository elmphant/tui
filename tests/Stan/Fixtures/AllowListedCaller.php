<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Stan\Fixtures;

final class AllowListedCaller
{
    public function replace(SelfCloneCaller $value): SelfCloneCaller
    {
        return $value->cloneWith(value: 99);
    }
}
