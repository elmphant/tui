<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Focus;

use Elmphant\Tui\Rendering\RenderContext;

trait DrawsFocus
{
    protected function drawsFocused(RenderContext $context): bool
    {
        return $context->hasFocus();
    }
}
