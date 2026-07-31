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
        $bufferWidth = $frame->width;
        $bufferHeight = $frame->height;

        $left = $area->x;
        $right = $area->right() - 1;
        $top = $area->y;
        $bottom = $area->bottom() - 1;

        // Direct array access — box-drawing glyphs are all width-1, skip CharWidth entirely.
        if ($top >= 0 && $top < $bufferHeight) {
            $topBase = $top * $bufferWidth;
            if ($left >= 0 && $left < $bufferWidth) {
                $i = $topBase + $left;
                $frame->characters[$i] = $glyphs->topLeft;
                $frame->foregrounds[$i] = $foreground;
                $frame->backgrounds[$i] = $background;
                $frame->attributes[$i] = $attributes;
                $frame->widths[$i] = 1;
            }
            if ($right >= 0 && $right < $bufferWidth) {
                $i = $topBase + $right;
                $frame->characters[$i] = $glyphs->topRight;
                $frame->foregrounds[$i] = $foreground;
                $frame->backgrounds[$i] = $background;
                $frame->attributes[$i] = $attributes;
                $frame->widths[$i] = 1;
            }
            $startX = max(0, $left + 1);
            $endX = min($bufferWidth, $right);
            for ($x = $startX; $x < $endX; ++$x) {
                $i = $topBase + $x;
                $frame->characters[$i] = $glyphs->horizontal;
                $frame->foregrounds[$i] = $foreground;
                $frame->backgrounds[$i] = $background;
                $frame->attributes[$i] = $attributes;
                $frame->widths[$i] = 1;
            }
            $frame->markRowDirty($top);
        }

        if ($bottom >= 0 && $bottom < $bufferHeight && $bottom !== $top) {
            $bottomBase = $bottom * $bufferWidth;
            if ($left >= 0 && $left < $bufferWidth) {
                $i = $bottomBase + $left;
                $frame->characters[$i] = $glyphs->bottomLeft;
                $frame->foregrounds[$i] = $foreground;
                $frame->backgrounds[$i] = $background;
                $frame->attributes[$i] = $attributes;
                $frame->widths[$i] = 1;
            }
            if ($right >= 0 && $right < $bufferWidth) {
                $i = $bottomBase + $right;
                $frame->characters[$i] = $glyphs->bottomRight;
                $frame->foregrounds[$i] = $foreground;
                $frame->backgrounds[$i] = $background;
                $frame->attributes[$i] = $attributes;
                $frame->widths[$i] = 1;
            }
            $startX = max(0, $left + 1);
            $endX = min($bufferWidth, $right);
            for ($x = $startX; $x < $endX; ++$x) {
                $i = $bottomBase + $x;
                $frame->characters[$i] = $glyphs->horizontal;
                $frame->foregrounds[$i] = $foreground;
                $frame->backgrounds[$i] = $background;
                $frame->attributes[$i] = $attributes;
                $frame->widths[$i] = 1;
            }
            $frame->markRowDirty($bottom);
        }

        $startY = max(0, $top + 1);
        $endY = min($bufferHeight, $bottom);
        for ($y = $startY; $y < $endY; ++$y) {
            $rowBase = $y * $bufferWidth;
            if ($left >= 0 && $left < $bufferWidth) {
                $i = $rowBase + $left;
                $frame->characters[$i] = $glyphs->vertical;
                $frame->foregrounds[$i] = $foreground;
                $frame->backgrounds[$i] = $background;
                $frame->attributes[$i] = $attributes;
                $frame->widths[$i] = 1;
            }
            if ($right >= 0 && $right < $bufferWidth) {
                $i = $rowBase + $right;
                $frame->characters[$i] = $glyphs->vertical;
                $frame->foregrounds[$i] = $foreground;
                $frame->backgrounds[$i] = $background;
                $frame->attributes[$i] = $attributes;
                $frame->widths[$i] = 1;
            }
            $frame->markRowDirty($y);
        }
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
