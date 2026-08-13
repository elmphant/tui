<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget;

use Elmphant\Tui\Core\Cloneable;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Layout\Spacing;
use Elmphant\Tui\Rendering\Block;
use Elmphant\Tui\Rendering\BorderStyle;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Style;

final readonly class Box implements Widget, WidgetContainer
{
    use Cloneable;

    /**
     * @param list<Widget> $children
     */
    public function __construct(
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

    public function sizing(): Sizing
    {
        $childHeight = 0;
        foreach ($this->children as $child) {
            $childHeight += max(1, $child->sizing()->rows->min);
        }

        $overhead = 2;
        if ($this->padding !== null) {
            $overhead += $this->padding->top + $this->padding->bottom;
        }

        return Sizing::of(Constraint::flex(), Constraint::exact($childHeight + $overhead));
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
    public function childWidgets(Rect $area): iterable
    {
        if ($area->width < 3 || $area->height < 3) {
            return;
        }

        $contentArea = $this->contentArea($area);
        $childY = $contentArea->y;

        foreach ($this->children as $child) {
            if ($childY >= $contentArea->bottom()) {
                return;
            }

            $childHeight = max(1, $child->sizing()->rows->min);

            yield [
                $child,
                new Rect($contentArea->x, $childY, $contentArea->width, min($childHeight, $contentArea->bottom() - $childY)),
            ];

            $childY += $childHeight;
        }
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        if ($area->width < 3 || $area->height < 3) {
            return;
        }

        $theme = $context->theme;
        $borderStyle = $this->borderStyleOverride ?? $theme->borderStyle;
        $titleStyle = $this->titleStyleOverride ?? $theme->titleStyle;

        $block = Block::new()
            ->border($theme->border)
            ->borderStyle($borderStyle)
            ->titleStyle($titleStyle)
            ->title($this->title);

        if ($this->padding !== null) {
            $block = $block->padding($this->padding);
        }

        $frame->pushClip($this->contentArea($area));

        try {
            foreach ($context->childrenOf($this, $area) as [$child, $childArea]) {
                $child->render($frame, $childArea, $context);
            }
        } finally {
            $frame->popClip();
        }

        $block->render($frame, $area);
    }

    /**
     * A Box always draws a border, so the inset does not depend on the theme.
     */
    private function contentArea(Rect $area): Rect
    {
        $block = Block::new()->border(BorderStyle::Solid);

        return ($this->padding === null ? $block : $block->padding($this->padding))->inner($area);
    }
}
