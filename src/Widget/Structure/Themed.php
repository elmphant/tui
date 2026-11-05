<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Structure;

use Closure;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Widget\MeasureHeight;
use Elmphant\Tui\Widget\PlacedChildren;
use Elmphant\Tui\Widget\Widget;
use Elmphant\Tui\Widget\WidgetContainer;

/**
 * The change is a function of the theme in force, so a theme switch still reaches the child.
 */
final readonly class Themed implements Widget, WidgetContainer, MeasureHeight
{
    /**
     * @param Closure(Theme): Theme $adjust
     */
    private function __construct(
        private Widget $child,
        private Closure $adjust,
    ) {
    }

    /**
     * @param Closure(Theme): Theme $adjust
     */
    public static function around(Widget $child, Closure $adjust): self
    {
        return new self($child, $adjust);
    }

    public function sizing(): Sizing
    {
        return $this->child->sizing();
    }

    public function measureHeight(int $width): int
    {
        return $this->child instanceof MeasureHeight
            ? $this->child->measureHeight($width)
            : $this->child->sizing()->rows->min;
    }

    /**
     * @return iterable<Widget>
     */
    public function children(): iterable
    {
        yield $this->child;
    }

    /**
     * @return iterable<array{Widget, Rect}>
     */
    public function placedChildren(Rect $area): iterable
    {
        yield [$this->child, $area];
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        $themed = $context->withTheme(($this->adjust)($context->theme));
        foreach (PlacedChildren::of($this, $area, $themed) as [$child, $childArea]) {
            $themed->draw($child, $frame, $childArea);
        }
    }
}
