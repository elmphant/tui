<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Layout\Invariant;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Widget\Widget;

final readonly class ConstrainedWidget implements Widget
{
    public function __construct(private Constraint $layoutConstraint)
    {
    }

    public function sizing(): Sizing
    {
        return Sizing::of($this->layoutConstraint, $this->layoutConstraint);
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
    }
}
