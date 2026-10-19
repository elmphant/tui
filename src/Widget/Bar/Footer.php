<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Bar;

use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Input\KeybindingsAware;
use Elmphant\Tui\Input\KeybindingsAwareBehavior;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Layout\Spacing;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Style;
use Elmphant\Tui\Support\Cloneable;
use Elmphant\Tui\Widget\Alignment;
use Elmphant\Tui\Widget\Interactive;
use Elmphant\Tui\Widget\Widget;
use Elmphant\Tui\Widget\WidgetContainer;

final readonly class Footer implements Interactive, WidgetContainer, KeybindingsAware
{
    use Cloneable;
    use KeybindingsAwareBehavior;
    private const SLOT_GAP = 2;

    /**
     * @param list<Widget> $shorterRight
     */
    private function __construct(
        private ?Widget $leftSlot = null,
        private ?Widget $centerSlot = null,
        private ?Widget $rightSlot = null,
        private ?Alignment $markedSlot = null,
        private ?Style $style = null,
        private ?Spacing $padding = null,
        private ?Spacing $margin = null,
        private ?Keybindings $customKeybindings = null,
        private ?Keybindings $globalKeybindings = null,
        private array $shorterRight = [],
    ) {
    }

    public static function new(): self
    {
        return new self();
    }

    public static function defaultKeybindings(): Keybindings
    {
        return Keybindings::empty();
    }

    /**
     * @param array<string, string> $hints
     */
    public static function forHints(array $hints): self
    {
        return new self(centerSlot: StatusBar::forHints($hints));
    }

    /**
     * @param array<string, string> $hints
     */
    public static function forLeftHints(array $hints): self
    {
        return new self(leftSlot: StatusBar::forHints($hints));
    }

    /**
     * @param array<string, string> $hints
     */
    public static function forRightHints(array $hints): self
    {
        return new self(rightSlot: StatusBar::forHints($hints));
    }

    public function withLeft(Widget $widget): self
    {
        return $this->cloneWith(leftSlot: $widget);
    }

    public function withCenter(Widget $widget): self
    {
        return $this->cloneWith(centerSlot: $widget);
    }

    /**
     * The right slot gives way first: beside a left slot that grows, the footer
     * draws the first of these forms, longest to shortest, that leaves the left
     * slot all it wants, and none of them when even the shortest would not.
     */
    public function withRight(Widget $widget, Widget ...$shorter): self
    {
        return $this->cloneWith(rightSlot: $widget, shorterRight: array_values($shorter));
    }

    /**
     * The slot a host routing keys by hand sends them to.
     */
    public function withMarkedSlot(?Alignment $slot): self
    {
        return $this->cloneWith(markedSlot: $slot);
    }

    public function withStyle(Style $style): self
    {
        return $this->cloneWith(style: $style);
    }

    public function withPadding(Spacing $padding): self
    {
        return $this->cloneWith(padding: $padding);
    }

    public function withMargin(Spacing $margin): self
    {
        return $this->cloneWith(margin: $margin);
    }

    public function sizing(): Sizing
    {
        $padding = $this->padding ?? Spacing::none();
        $margin = $this->margin ?? Spacing::none();

        return Sizing::of(
            Constraint::flex(),
            Constraint::exact(1 + $padding->top + $padding->bottom + $margin->top + $margin->bottom),
        );
    }

    /**
     * @return list<Widget>
     */
    public function children(): array
    {
        return array_values(array_filter([$this->leftSlot, $this->centerSlot, $this->rightSlot]));
    }

    /**
     * @return list<array{Widget, Rect}>
     */
    public function placedChildren(Rect $area): array
    {
        $contentArea = $this->contentArea($area);

        if (null === $contentArea) {
            return [];
        }

        $placements = BarSlotRenderer::placements([...$this->slots(), 'leftSlot' => null], $contentArea);
        $left = $this->leftRect($contentArea, rightSlot: $this->rightSlot);

        return $left === null || $this->leftSlot === null
            ? array_values($placements)
            : [[$this->leftSlot, $left], ...array_values($placements)];
    }

    /**
     * Where the right slot is drawn, in whichever form fits beside these hints of
     * whatever holds the keyboard; null where it has given way. For a host that
     * hit-tests the slot itself.
     *
     * @param array<string, string> $focusedHints
     */
    public function rightSlotArea(Rect $area, array $focusedHints = []): ?Rect
    {
        $contentArea = $this->contentArea($area);

        if ($contentArea === null) {
            return null;
        }

        $leftWidth = match (true) {
            $this->leftSlot instanceof FocusHints => $this->leftSlot->widthWith($focusedHints),
            $this->leftSlot !== null => $this->leftSlot->sizing()->columns->intrinsic(),
            default => 0,
        };
        $right = $this->rightBeside($contentArea, $leftWidth);

        return $right === null ? null : BarSlotRenderer::rectFor($right, Alignment::Right, $contentArea);
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        $contentArea = $this->contentArea($area);

        if (null === $contentArea) {
            return new UpdateResult($this);
        }

        [$overrides, $command] = BarSlotRenderer::updateSlots($this->slots(), $message, $contentArea, $this->markedSlot, $this->globalKeybindings);

        if ($overrides !== []) {
            return new UpdateResult($this->cloneWith(...$overrides), $command);
        }

        return new UpdateResult($this, $command);
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        $geometry = $this->geometry($area);

        if ($geometry === null) {
            return;
        }

        $barArea = $geometry['bar'];

        $theme = $context->theme;
        $barStyle = $this->style ?? $theme->footerStyle;
        $barBg = $barStyle->backgroundValue();

        $frame->fillRect($barArea->x, $barArea->y, $barArea->width, $barArea->height, $barStyle->foregroundValue(), $barBg, $barStyle->attributes);

        $contentArea = $geometry['content'];

        if ($contentArea === null) {
            return;
        }

        $contentX = $contentArea->x;
        $contentY = $contentArea->y;
        $contentWidth = $contentArea->width;

        $right = $this->rightDrawn($contentArea, $context);

        if ($this->leftSlot !== null) {
            BarSlotRenderer::drawIn($frame, $this->leftSlot, $this->leftRect($contentArea, $context, $right), $barBg, $context);
        }

        if ($this->centerSlot !== null) {
            BarSlotRenderer::renderCentered($frame, $this->centerSlot, $contentX, $contentY, $contentWidth, $barBg, $context);
        }

        if ($right !== null) {
            BarSlotRenderer::renderRight($frame, $right, $contentX, $contentY, $contentWidth, $barBg, $context);
        }
    }

    /**
     * @return array{leftSlot: ?Widget, centerSlot: ?Widget, rightSlot: ?Widget}
     */
    private function slots(): array
    {
        return [
            'leftSlot' => $this->leftSlot,
            'centerSlot' => $this->centerSlot,
            'rightSlot' => $this->rightSlot,
        ];
    }

    /**
     * A left slot that grows takes the row up to the right slot, unless a centre
     * slot needs the middle.
     */
    private function leftRect(Rect $contentArea, ?RenderContext $context = null, ?Widget $rightSlot = null): ?Rect
    {
        if ($this->leftSlot === null) {
            return null;
        }

        if (!$this->leftGrows()) {
            return BarSlotRenderer::rectFor($this->leftSlot, Alignment::Left, $contentArea, $context);
        }

        $right = $rightSlot === null ? null : BarSlotRenderer::rectFor($rightSlot, Alignment::Right, $contentArea, $context);
        $width = $right === null ? $contentArea->width : $right->x - $contentArea->x - self::SLOT_GAP;

        return $width < 1 ? null : new Rect($contentArea->x, $contentArea->y, $width, 1);
    }

    private function leftGrows(): bool
    {
        return $this->leftSlot !== null && $this->centerSlot === null && $this->leftSlot->sizing()->columns->flex > 0;
    }

    private function rightDrawn(Rect $contentArea, RenderContext $context): ?Widget
    {
        $left = $this->leftSlot === null
            ? null
            : BarSlotRenderer::rectFor($this->leftSlot, Alignment::Left, new Rect($contentArea->x, $contentArea->y, \PHP_INT_MAX, 1), $context);

        return $this->rightBeside($contentArea, $left === null ? 0 : $left->width);
    }

    private function rightBeside(Rect $contentArea, int $leftWidth): ?Widget
    {
        if ($this->rightSlot === null || $this->leftSlot === null || !$this->leftGrows()) {
            return $this->rightSlot;
        }

        $room = $contentArea->width - ($leftWidth === 0 ? 0 : $leftWidth + self::SLOT_GAP);

        foreach ([$this->rightSlot, ...$this->shorterRight] as $form) {
            if ($form->sizing()->columns->intrinsic() <= $room) {
                return $form;
            }
        }

        return null;
    }

    private function contentArea(Rect $area): ?Rect
    {
        return $this->geometry($area)['content'] ?? null;
    }

    /**
     * @return array{bar: Rect, content: ?Rect}|null
     */
    private function geometry(Rect $area): ?array
    {
        $innerArea = ($this->margin ?? Spacing::none())->applyTo($area);

        if ($innerArea->width < 1 || $innerArea->height < 1) {
            return null;
        }

        $padding = $this->padding ?? Spacing::none();
        $contentArea = $padding->applyTo($innerArea);

        return [
            'bar' => $innerArea,
            'content' => $contentArea->width > 0 && $contentArea->height > 0 ? $contentArea : null,
        ];
    }
}
