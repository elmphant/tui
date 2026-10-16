<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Widget\Focus\FocusScope;
use Elmphant\Tui\Widget\Widget;
use Elmphant\Tui\Widget\WidgetContainer;

final readonly class FilterRowScope implements Widget, WidgetContainer, FocusScope
{
    public function __construct(private Widget $row)
    {
    }

    public function sizing(): Sizing
    {
        return $this->row->sizing();
    }

    public function children(): iterable
    {
        return [$this->row];
    }

    public function placedChildren(Rect $area): iterable
    {
        return [[$this->row, $area]];
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        $this->row->render($frame, $area, $context);
    }
}
