<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Popup;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Inset;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Rendering\Block;
use Elmphant\Tui\Rendering\BorderType;
use Elmphant\Tui\Rendering\Context\FloatTarget;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Widget\PlacedChildren;
use Elmphant\Tui\Widget\Widget;

/**
 * @internal the floating half of a Dropdown, queued by Dropdown::render()
 *
 * Never `Dropdown` itself — the drain would redraw and requeue it
 */
final readonly class DropdownPopup implements PopupFrame, Widget
{
    private const BORDERS = 2;

    private const SCROLLBAR = 1;

    private const MIN_SIDE = 3;

    private function __construct(
        private DropdownContent $content,
        private FloatTarget $floatTarget,
    ) {
    }

    public static function of(DropdownContent $content, FloatTarget $target): self
    {
        return new self($content, $target);
    }

    public function content(): DropdownContent
    {
        return $this->content;
    }

    public function contentArea(Rect $area): Rect
    {
        return (new Inset(border: true))->inner($area);
    }

    /**
     * @return iterable<Widget> children in visual order, without resolving layout
     */
    public function children(): iterable
    {
        yield $this->content;
    }

    /**
     * @return iterable<array{0: Widget, 1: Rect}>
     */
    public function placedChildren(Rect $area): iterable
    {
        yield [$this->content, $this->contentArea($area)];
    }

    public function target(): FloatTarget
    {
        return $this->floatTarget;
    }

    public function captures(MouseAction $action): bool
    {
        return match ($action) {
            MouseAction::Press, MouseAction::WheelUp, MouseAction::WheelDown,
            MouseAction::WheelLeft, MouseAction::WheelRight => true,
            MouseAction::Motion, MouseAction::Release => false,
        };
    }

    public function placement(Rect $anchor, Rect $viewport): Rect
    {
        $below = max(0, $viewport->bottom() - $anchor->bottom());
        $above = max(0, $anchor->y - $viewport->y);

        [$width, $wanted] = $this->measured($anchor, $viewport, $this->cap($viewport->height));

        $opensDown = $wanted <= $below || $below >= $above;
        $height = min($wanted, $opensDown ? $below : $above);

        $x = max($viewport->x, min($anchor->x, $viewport->right() - $width));
        $y = $opensDown ? $anchor->bottom() : $anchor->y - $height;

        if ($width < self::MIN_SIDE || $height < self::MIN_SIDE) {
            return new Rect($x, $y, 0, 0);
        }

        return new Rect($x, $y, $width, $height);
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::flex());
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        if ($area->width < self::MIN_SIDE || $area->height < self::MIN_SIDE) {
            return;
        }

        $frame->fillStyled($area, $context->theme->base);
        Block::new()->withBorderType(BorderType::Rounded)->withBorderStyle($context->theme->focusedBorderStyle)->render($frame, $area);

        // A float draws after the tree has closed the dropdown's focus, and the dropdown queues it only while it holds the keyboard.
        $context->focusHere(function () use ($frame, $area, $context): void {
            foreach (PlacedChildren::of($this, $area, $context) as [$child, $childArea]) {
                $context->draw($child, $frame, $childArea);
            }
        });
    }

    /**
     * Resolve width before height; add a scrollbar column only if content is clipped and existing spare width cannot hold it.
     *
     * @return array{0: int, 1: int}
     */
    private function measured(Rect $anchor, Rect $viewport, int $cap): array
    {
        $width = $this->widthFor($anchor, $viewport, 0);
        $height = self::BORDERS + $this->content->popupHeight($width - self::BORDERS);

        if ($height <= $cap) {
            return [$width, $height];
        }

        $scrolling = $this->widthFor($anchor, $viewport, self::SCROLLBAR);
        $inner = max(0, $scrolling - self::BORDERS - self::SCROLLBAR);

        return [$scrolling, min($cap, self::BORDERS + $this->content->popupHeight($inner))];
    }

    private function widthFor(Rect $anchor, Rect $viewport, int $scrollbar): int
    {
        return min(
            $viewport->width,
            max($anchor->width, $this->content->popupWidth() + self::BORDERS + $scrollbar),
        );
    }

    private function cap(int $viewportHeight): int
    {
        return max(intdiv($viewportHeight, 2), self::BORDERS + 1);
    }
}
