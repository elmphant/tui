<?php

declare(strict_types=1);

namespace Elmphant\Tui\Rendering;

use Elmphant\Tui\Layout\Inset;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Spacing;
use Elmphant\Tui\Support\Cloneable;
use Elmphant\Tui\Text\CharWidth;

final readonly class Block
{
    use Cloneable;

    private function __construct(
        private ?BorderType $border = null,
        private ?Spacing $padding = null,
        private ?Spacing $margin = null,
        private ?string $title = null,
        private Style $borderStyle = new Style(),
        private Style $titleStyle = new Style(),
    ) {
    }

    public static function new(): self
    {
        return new self();
    }

    public function withBorderType(BorderType $border): static
    {
        return $this->cloneWith(border: $border);
    }

    public function withPadding(Spacing $padding): static
    {
        return $this->cloneWith(padding: $padding);
    }

    public function withMargin(Spacing $margin): static
    {
        return $this->cloneWith(margin: $margin);
    }

    public function withTitle(string $title): static
    {
        return $this->cloneWith(title: $title);
    }

    public function withBorderStyle(Style $style): static
    {
        return $this->cloneWith(borderStyle: $style);
    }

    public function withTitleStyle(Style $style): static
    {
        return $this->cloneWith(titleStyle: $style);
    }

    public function inner(Rect $area): Rect
    {
        return $this->inset()->inner($area);
    }

    public function render(Frame $frame, Rect $area): void
    {
        $borderArea = $this->inset()->margin->applyTo($area);

        if ($this->border !== null) {
            $this->renderBorder($frame, $borderArea, $this->border);
        }

        if ($this->title !== null && $this->title !== '' && $this->border !== null) {
            $this->renderTitle($frame, $borderArea);
        }
    }

    private function inset(): Inset
    {
        return new Inset($this->margin, $this->border !== null, $this->padding);
    }

    private function renderBorder(Frame $frame, Rect $area, BorderType $border): void
    {
        $style = $this->borderStyle;
        $glyphs = $border->glyphs();

        $left = $area->x;
        $right = $area->right() - 1;
        $top = $area->y;
        $bottom = $area->bottom() - 1;
        $horizontalWidth = max(0, $right - $left - 1);
        $verticalHeight = max(0, $bottom - $top - 1);

        $frame->putStyled($left, $top, $glyphs->topLeft, $style);
        $frame->putStyled($right, $top, $glyphs->topRight, $style);
        $frame->fillStyled(new Rect($left + 1, $top, $horizontalWidth, 1), $style, $glyphs->horizontal);

        if ($bottom !== $top) {
            $frame->putStyled($left, $bottom, $glyphs->bottomLeft, $style);
            $frame->putStyled($right, $bottom, $glyphs->bottomRight, $style);
            $frame->fillStyled(new Rect($left + 1, $bottom, $horizontalWidth, 1), $style, $glyphs->horizontal);
        }

        $frame->fillStyled(new Rect($left, $top + 1, 1, $verticalHeight), $style, $glyphs->vertical);
        $frame->fillStyled(new Rect($right, $top + 1, 1, $verticalHeight), $style, $glyphs->vertical);
    }

    private function renderTitle(Frame $frame, Rect $area): void
    {
        $maxWidth = $area->width - 4;
        if ($maxWidth <= 0) {
            return;
        }

        $title = CharWidth::truncateToWidth($this->title ?? '', $maxWidth);
        $space = new Style($this->titleStyle->foreground, $this->titleStyle->background);
        $frame->putStyled($area->x + 1, $area->y, ' ', $space);
        $frame->putStyled($area->x + 2, $area->y, $title, $this->titleStyle);
        $frame->putStyled($area->x + 2 + CharWidth::stringWidth($title), $area->y, ' ', $space);
    }
}
