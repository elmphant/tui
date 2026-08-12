<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Stan\Fixtures;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Widget\Widget;

final readonly class OptionalChildWidget implements Widget
{
    public function __construct(private ?Widget $slot = null)
    {
    }

    public function constraint(): Constraint
    {
        return Constraint::flex();
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        $this->slot?->render($frame, $area, $context);
    }
}
