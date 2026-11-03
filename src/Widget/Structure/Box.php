<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Structure;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Inset;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Layout\Spacing;
use Elmphant\Tui\Rendering\Block;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Style;
use Elmphant\Tui\Support\Cloneable;
use Elmphant\Tui\Widget\MeasureHeight;
use Elmphant\Tui\Widget\PlacedChildren;
use Elmphant\Tui\Widget\Widget;
use Elmphant\Tui\Widget\WidgetContainer;

final readonly class Box implements Widget, WidgetContainer, MeasureHeight
{
    use Cloneable;

    /**
     * @param list<Widget> $children
     */
    private function __construct(
        private string $title = '',
        private array $children = [],
        private ?Style $borderStyleOverride = null,
        private ?Style $titleStyleOverride = null,
        private ?Spacing $padding = null,
    ) {
    }

    public static function new(string $title = ''): self
    {
        return new self(title: $title);
    }

    public function withTitle(string $title): self
    {
        return $this->cloneWith(title: $title);
    }

    /**
     * @param list<Widget> $children
     */
    public function withChildren(array $children): self
    {
        return $this->cloneWith(children: $children);
    }

    public function add(Widget $child): self
    {
        return $this->cloneWith(children: [...$this->children, $child]);
    }

    public function withPadding(Spacing $padding): self
    {
        return $this->cloneWith(padding: $padding);
    }

    public function withBorderStyle(Style $style): self
    {
        return $this->cloneWith(borderStyleOverride: $style);
    }

    public function withTitleStyle(Style $style): self
    {
        return $this->cloneWith(titleStyleOverride: $style);
    }

    /**
     * Request measurement because wrapped content height depends on width.
     */
    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::content());
    }

    public function measureHeight(int $width): int
    {
        $padding = $this->padding;
        $sides = $padding === null ? 0 : $padding->left + $padding->right;
        $ends = $padding === null ? 0 : $padding->top + $padding->bottom;
        $inner = max(0, $width - 2 - $sides);
        $childHeight = 0;

        foreach ($this->children as $child) {
            $childHeight += $child instanceof MeasureHeight
                ? max(1, $child->measureHeight($inner))
                : max(1, $child->sizing()->rows->min);
        }

        return $childHeight + 2 + $ends;
    }

    /**
     * @return iterable<Widget>
     */
    public function children(): iterable
    {
        yield from $this->children;
    }

    /**
     * @return iterable<array{Widget, Rect}>
     */
    public function placedChildren(Rect $area): iterable
    {
        $contentArea = $this->contentArea($area);
        $childY = $contentArea->y;

        foreach ($this->children as $child) {
            if ($childY >= $contentArea->bottom()) {
                return;
            }

            // Measure at the available width; a row minimum does not account for wrapping.
            $childHeight = $child instanceof MeasureHeight
                ? max(1, $child->measureHeight($contentArea->width))
                : max(1, $child->sizing()->rows->min);

            yield [
                $child,
                new Rect($contentArea->x, $childY, $contentArea->width, min($childHeight, $contentArea->bottom() - $childY)),
            ];

            $childY += $childHeight;
        }
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        $inset = $this->insetIn($area);

        $frame->pushClip($inset->inner($area));

        try {
            foreach (PlacedChildren::of($this, $area, $context) as [$child, $childArea]) {
                $child->render($frame, $childArea, $context);
            }
        } finally {
            $frame->popClip();
        }

        if (!$inset->border) {
            return;
        }

        $theme = $context->theme;
        $block = Block::new()
            ->withBorder($theme->border)
            ->withBorderStyle($this->borderStyleOverride ?? $theme->borderStyle)
            ->withTitleStyle($this->titleStyleOverride ?? $theme->titleStyle)
            ->withTitle($this->title);

        $block->render($frame, $area);
    }

    private function contentArea(Rect $area): Rect
    {
        return $this->insetIn($area)->inner($area);
    }

    private function insetIn(Rect $area): Inset
    {
        return (new Inset(border: true, padding: $this->padding))->fitting($area);
    }
}
