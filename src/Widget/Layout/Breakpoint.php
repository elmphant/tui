<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Layout;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Widget\MeasureHeight;
use Elmphant\Tui\Widget\Widget;
use Elmphant\Tui\Widget\WidgetContainer;

/**
 * Both layouts must contain the same components: discovery uses the wide layout without knowing the available width.
 */
final readonly class Breakpoint implements MeasureHeight, Widget, WidgetContainer
{
    private function __construct(
        private int $columns,
        private Widget $narrow,
        private Widget $wide,
    ) {
    }

    public static function at(int $columns, Widget $narrow, Widget $wide): self
    {
        return new self($columns, $narrow, $wide);
    }

    public function sizing(): Sizing
    {
        return $this->wide->sizing();
    }

    public function measureHeight(int $width): int
    {
        $arrangement = $this->for($width);

        return $arrangement instanceof MeasureHeight ? $arrangement->measureHeight($width) : $arrangement->sizing()->rows->min;
    }

    /**
     * @return list<Widget>
     */
    public function children(): iterable
    {
        return [$this->wide];
    }

    /**
     * @return list<array{0: Widget, 1: Rect}>
     */
    public function placedChildren(Rect $area): iterable
    {
        return [[$this->for($area->width), $area]];
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        $context->draw($this->for($area->width), $frame, $area);
    }

    private function for(int $width): Widget
    {
        return $width >= $this->columns ? $this->wide : $this->narrow;
    }
}
