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
        private ?BorderStyle $border = null,
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

    public function withBorder(BorderStyle $border): static
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
        $borderArea = $area;

        if ($this->margin !== null) {
            $borderArea = $borderArea->shrink(
                $this->margin->top,
                $this->margin->right,
                $this->margin->bottom,
                $this->margin->left,
            );
        }

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

    private function renderBorder(Frame $frame, Rect $area, BorderStyle $border): void
    {
        $foreground = $this->borderStyle->foregroundValue();
        $background = $this->borderStyle->backgroundValue();
        $attributes = $this->borderStyle->attributes;
        $glyphs = $border->glyphs();

        $left = $area->x;
        $right = $area->right() - 1;
        $top = $area->y;
        $bottom = $area->bottom() - 1;
        $horizontalWidth = $right - $left - 1;
        $verticalHeight = $bottom - $top - 1;

        $frame->putChar($left, $top, $glyphs->topLeft, $foreground, $background, $attributes);
        $frame->putChar($right, $top, $glyphs->topRight, $foreground, $background, $attributes);
        $frame->fillRect($left + 1, $top, $horizontalWidth, 1, $foreground, $background, $attributes, $glyphs->horizontal);

        if ($bottom !== $top) {
            $frame->putChar($left, $bottom, $glyphs->bottomLeft, $foreground, $background, $attributes);
            $frame->putChar($right, $bottom, $glyphs->bottomRight, $foreground, $background, $attributes);
            $frame->fillRect($left + 1, $bottom, $horizontalWidth, 1, $foreground, $background, $attributes, $glyphs->horizontal);
        }

        $frame->fillRect($left, $top + 1, 1, $verticalHeight, $foreground, $background, $attributes, $glyphs->vertical);
        $frame->fillRect($right, $top + 1, 1, $verticalHeight, $foreground, $background, $attributes, $glyphs->vertical);
    }

    private function renderTitle(Frame $frame, Rect $area): void
    {
        $foreground = $this->titleStyle->foregroundValue();
        $background = $this->titleStyle->backgroundValue();
        $attributes = $this->titleStyle->attributes;

        $maxWidth = $area->width - 4;
        if ($maxWidth <= 0) {
            return;
        }

        $title = CharWidth::truncateToWidth($this->title ?? '', $maxWidth);
        $frame->putChar($area->x + 1, $area->y, ' ', $foreground, $background);
        $frame->putString($area->x + 2, $area->y, $title, $foreground, $background, $attributes);
        $frame->putChar($area->x + 2 + CharWidth::stringWidth($title), $area->y, ' ', $foreground, $background);
    }
}
