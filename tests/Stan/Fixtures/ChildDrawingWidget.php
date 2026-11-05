<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Stan\Fixtures;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Rendering\Block;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Widget\Widget;

final readonly class ChildDrawingWidget implements Widget
{
    public function __construct(private Widget $first, private Widget $second)
    {
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::flex());
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        Block::new()->render($frame, $area);
        $context->draw($this->first, $frame, $area);
        $this->second->render($frame, $area, $context);
    }
}

final readonly class ChildDrawingHost
{
    public function __construct(private Widget $root)
    {
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        $this->root->render($frame, $area, $context);
    }
}
