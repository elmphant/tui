<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Focus;

use Elmphant\Tui\Widget\Widget;
use Elmphant\Tui\Widget\WidgetContainer;

/**
 * Expose each focusable child as its own tab stop, indexed in children() order.
 */
interface GraftsItsChildren extends WidgetContainer
{
    public function withChildAt(int $index, Widget $child): static;
}
