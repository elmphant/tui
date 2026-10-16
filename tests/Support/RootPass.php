<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Support;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\PlannedLayout;
use Elmphant\Tui\Rendering\RenderPass;
use Elmphant\Tui\Widget\Widget;

final class RootPass
{
    public static function draw(RenderPass $pass, Widget $root, Frame $frame, ?Rect $area = null, ?PlannedLayout $plan = null): void
    {
        $area ??= $frame->area();
        $pass->floats->drawOver(
            $frame,
            static fn () => $pass->layout->during($plan, static fn () => $root->render($frame, $area, $pass->context)),
            $pass->context,
        );
    }
}
