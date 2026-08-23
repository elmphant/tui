<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Focus;

use Elmphant\Tui\Rendering\RenderContext;

/**
 * @internal transitional while focus moves from the widget to the tree: the
 *           flag still answers where no tree has said. One body to change when
 *           it goes — see docs/plans/2026-08-16-focus-ownership-plan.md
 */
trait DrawsFocus
{
    protected function drawsFocused(RenderContext $context): bool
    {
        return $context->showsFocus($this->isFocused());
    }
}
