<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Stan\Fixtures;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Widget\Widget;

final readonly class AxisPolicyPublisher implements Widget
{
    public function constraint(): Constraint
    {
        return $this->preferredWidth();
    }

    public function columnWidth(): Constraint
    {
        return Constraint::exact(4);
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::exact(1), Constraint::exact(1));
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
    }

    private function preferredWidth(): Constraint
    {
        return Constraint::flex();
    }
}
