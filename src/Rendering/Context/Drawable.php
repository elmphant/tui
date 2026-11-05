<?php

declare(strict_types=1);

namespace Elmphant\Tui\Rendering\Context;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Frame;

/**
 * What RenderContext::draw() takes: a widget or a float.
 */
interface Drawable
{
    public function render(Frame $frame, Rect $area, RenderContext $context): void;
}
