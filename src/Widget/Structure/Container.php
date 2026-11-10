<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Structure;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Direction;
use Elmphant\Tui\Layout\Inset;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Layout\Spacing;
use Elmphant\Tui\Rendering\Block;
use Elmphant\Tui\Rendering\BorderType;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Style;
use Elmphant\Tui\Support\Cloneable;
use Elmphant\Tui\Widget\Alignment;
use Elmphant\Tui\Widget\Focus\GraftsItsChildren;
use Elmphant\Tui\Widget\MeasureHeight;
use Elmphant\Tui\Widget\PlacedChildren;
use Elmphant\Tui\Widget\VerticalAlignment;
use Elmphant\Tui\Widget\Widget;
use InvalidArgumentException;

final readonly class Container implements Widget, GraftsItsChildren, MeasureHeight
{
    use Cloneable;

    private function __construct(
        private Widget $child,
        private ?Spacing $padding = null,
        private ?Spacing $margin = null,
        private ?Style $style = null,
        private ?BorderType $border = null,
        private ?Style $borderStyle = null,
        private ?Sizing $sizingOverride = null,
        private ?int $contentWidth = null,
        private ?int $contentHeight = null,
        private Alignment $horizontalAlignment = Alignment::Left,
        private VerticalAlignment $verticalAlignment = VerticalAlignment::Top,
    ) {
    }

    public static function around(Widget $child): self
    {
        return new self($child);
    }

    public function withPadding(Spacing $padding): self
    {
        return $this->cloneWith(padding: $padding);
    }

    public function withMargin(Spacing $margin): self
    {
        return $this->cloneWith(margin: $margin);
    }

    public function withStyle(Style $style): self
    {
        return $this->cloneWith(style: $style);
    }

    public function withBorderType(BorderType $border): self
    {
        return $this->cloneWith(border: $border);
    }

    public function withBorderStyle(Style $style): self
    {
        return $this->cloneWith(borderStyle: $style);
    }

    public function withChildAt(int $index, Widget $child): static
    {
        return $index === 0 ? $this->cloneWith(child: $child) : $this;
    }

    public function withSizing(Sizing $sizing): self
    {
        return $this->cloneWith(sizingOverride: $sizing);
    }

    public function withContentWidth(int $width): self
    {
        self::assertNonNegativeDimension('width', $width);

        return $this->cloneWith(contentWidth: $width);
    }

    public function withContentHeight(int $height): self
    {
        self::assertNonNegativeDimension('height', $height);

        return $this->cloneWith(contentHeight: $height);
    }

    public function withContentSize(int $width, int $height): self
    {
        self::assertNonNegativeDimension('width', $width);
        self::assertNonNegativeDimension('height', $height);

        return $this->cloneWith(contentWidth: $width, contentHeight: $height);
    }

    public function withHorizontalAlignment(Alignment $alignment): self
    {
        return $this->cloneWith(horizontalAlignment: $alignment);
    }

    public function withVerticalAlignment(VerticalAlignment $alignment): self
    {
        return $this->cloneWith(verticalAlignment: $alignment);
    }

    public function sizing(): Sizing
    {
        return $this->sizingOverride ?? Sizing::of(
            $this->axis(Direction::Horizontal),
            $this->axis(Direction::Vertical),
        );
    }

    public function measureHeight(int $width): int
    {
        $availableWidth = max(0, $width - $this->horizontalOverhead());
        if ($this->contentWidth !== null) {
            $availableWidth = min($availableWidth, $this->contentWidth);
        }

        $contentHeight = $this->contentHeight ?? ($this->child instanceof MeasureHeight
            ? $this->child->measureHeight($availableWidth)
            : self::naturalHeight($this->child->sizing()->rows));

        $measured = $contentHeight + $this->verticalOverhead();
        if ($this->sizingOverride === null) {
            return $measured;
        }

        return min($this->sizingOverride->rows->max, max($this->sizingOverride->rows->min, $measured));
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
        yield [$this->child, $this->contentArea($area)];
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        $frame->clipped($this->contentArea($area), function () use ($frame, $area, $context): void {
            foreach (PlacedChildren::of($this, $area, $context) as [$child, $childArea]) {
                $context->drawChild(0, $child, $frame, $childArea);
            }
        });

        $this->block($context)->render($frame, $area);

        if ($this->style !== null) {
            $frame->underlay($area->inset($this->margin ?? Spacing::none()), $this->style);
        }
    }

    private function axis(Direction $direction): Constraint
    {
        $isVertical = $direction === Direction::Vertical;
        $overhead = $isVertical
            ? $this->verticalOverhead()
            : $this->horizontalOverhead();
        $contentSize = $isVertical ? $this->contentHeight : $this->contentWidth;
        if ($contentSize !== null) {
            return Constraint::exact($contentSize + $overhead);
        }

        $child = $this->child->sizing()->on($direction);

        $grown = $child
            ->withMax(self::grownBy($child->max, $overhead))
            ->withMin($child->min + $overhead);

        return $child->basis === null ? $grown : $grown->withBasis(self::grownBy($child->basis, $overhead));
    }

    private function contentArea(Rect $area): Rect
    {
        $inner = (new Inset($this->margin, $this->border !== null, $this->padding))->inner($area);

        $width = $this->contentWidth === null
            ? $inner->width
            : min($inner->width, $this->contentWidth);
        $height = $this->contentHeight === null
            ? $inner->height
            : min($inner->height, $this->contentHeight);

        $x = match ($this->horizontalAlignment) {
            Alignment::Left => $inner->x,
            Alignment::Center => $inner->x + intdiv($inner->width - $width, 2),
            Alignment::Right => $inner->right() - $width,
        };
        $y = match ($this->verticalAlignment) {
            VerticalAlignment::Top => $inner->y,
            VerticalAlignment::Center => $inner->y + intdiv($inner->height - $height, 2),
            VerticalAlignment::Bottom => $inner->bottom() - $height,
        };

        return new Rect($x, $y, $width, $height);
    }

    private function block(RenderContext $context): Block
    {
        $block = Block::new();

        if ($this->margin !== null) {
            $block = $block->withMargin($this->margin);
        }
        if ($this->border !== null) {
            $block = $block
                ->withBorderType($this->border)
                ->withBorderStyle($this->borderStyle ?? $context->theme->borderStyle);
        }
        if ($this->padding !== null) {
            $block = $block->withPadding($this->padding);
        }

        return $block;
    }

    private function horizontalOverhead(): int
    {
        return ($this->margin === null ? 0 : $this->margin->left + $this->margin->right)
            + ($this->padding === null ? 0 : $this->padding->left + $this->padding->right)
            + ($this->border === null ? 0 : 2);
    }

    private function verticalOverhead(): int
    {
        return ($this->margin === null ? 0 : $this->margin->top + $this->margin->bottom)
            + ($this->padding === null ? 0 : $this->padding->top + $this->padding->bottom)
            + ($this->border === null ? 0 : 2);
    }

    private static function grownBy(int $size, int $overhead): int
    {
        return $size > PHP_INT_MAX - $overhead ? PHP_INT_MAX : $size + $overhead;
    }

    private static function naturalHeight(Constraint $constraint): int
    {
        return $constraint->min === $constraint->max
            ? $constraint->min
            : max(1, $constraint->min);
    }

    private static function assertNonNegativeDimension(string $name, int $value): void
    {
        if ($value < 0) {
            throw new InvalidArgumentException(sprintf('Container content %s must be non-negative, got %d', $name, $value));
        }
    }
}
