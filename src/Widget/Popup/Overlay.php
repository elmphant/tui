<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Popup;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Widget\Widget;
use Elmphant\Tui\Widget\WidgetContainer;

final readonly class Overlay implements Widget, WidgetContainer
{
    private function __construct(
        private Widget $base,
        private Widget $overlay,
    ) {
    }

    public static function new(Widget $base, Widget $overlay): self
    {
        return new self($base, $overlay);
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::flex());
    }

    /**
     * @return list<Widget>
     */
    public function children(): array
    {
        return [$this->base, $this->overlay];
    }

    /**
     * @return list<array{Widget, Rect}>
     */
    public function childWidgets(Rect $area): array
    {
        return [
            [$this->base, $area],
            [$this->overlay, $area],
        ];
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        foreach ($context->childrenOf($this, $area) as [$child, $childArea]) {
            $child->render($frame, $childArea, $context);
        }
    }
}
