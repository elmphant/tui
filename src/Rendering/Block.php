<?php

declare(strict_types=1);

namespace Elmphant\Tui\Rendering;

use Elmphant\Tui\Core\Cloneable;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Spacing;

final readonly class Block
{
    use Cloneable;

    public function __construct(
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

    public function border(BorderStyle $border): static
    {
        return $this->cloneWith(border: $border);
    }

    public function padding(Spacing $padding): static
    {
        return $this->cloneWith(padding: $padding);
    }

    public function margin(Spacing $margin): static
    {
        return $this->cloneWith(margin: $margin);
    }

    public function title(string $title): static
    {
        return $this->cloneWith(title: $title);
    }

    public function borderStyle(Style $style): static
    {
        return $this->cloneWith(borderStyle: $style);
    }

    public function titleStyle(Style $style): static
    {
        return $this->cloneWith(titleStyle: $style);
    }

    public function inner(Rect $area): Rect
    {
        $result = $area;

        if ($this->margin !== null) {
            $result = $result->shrink(
                $this->margin->top,
                $this->margin->right,
                $this->margin->bottom,
                $this->margin->left,
            );
        }

        if ($this->border !== null) {
            $result = $result->shrink(1, 1, 1, 1);
        }

        if ($this->padding !== null) {
            $result = $result->shrink(
                $this->padding->top,
                $this->padding->right,
                $this->padding->bottom,
                $this->padding->left,
            );
        }

        return $result;
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
            $this->renderBorder($frame, $borderArea);
        }

        if ($this->title !== null && $this->title !== '' && $this->border !== null) {
            $this->renderTitle($frame, $borderArea);
        }
    }

    private function renderBorder(Frame $frame, Rect $area): void
    {
        $border = $this->border;
        if ($border === null) {
            return;
        }
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

        if ($top >= 0 && $top < $frame->height) {
            $frame->putChar($left, $top, $glyphs->topLeft, $foreground, $background, $attributes);
            $frame->putChar($right, $top, $glyphs->topRight, $foreground, $background, $attributes);
            $frame->fillRect($left + 1, $top, $horizontalWidth, 1, $foreground, $background, $attributes, $glyphs->horizontal);
        }

        if ($bottom >= 0 && $bottom < $frame->height && $bottom !== $top) {
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
