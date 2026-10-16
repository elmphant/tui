<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;

interface Widget
{
    public function sizing(): Sizing;

    public function render(Frame $frame, Rect $area, RenderContext $context): void;
}
