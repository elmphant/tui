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

    public function withRight(Widget $widget): self
    {
        return $this->cloneWith(rightSlot: $widget);
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
        $left = $this->leftRect($contentArea);

        return $left === null || $this->leftSlot === null
            ? array_values($placements)
            : [[$this->leftSlot, $left], ...array_values($placements)];
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

        if ($this->leftSlot !== null) {
            BarSlotRenderer::drawIn($frame, $this->leftSlot, $this->leftRect($contentArea), $barBg, $context);
        }

        if ($this->centerSlot !== null) {
            BarSlotRenderer::renderCentered($frame, $this->centerSlot, $contentX, $contentY, $contentWidth, $barBg, $context);
        }

        if ($this->rightSlot !== null) {
            BarSlotRenderer::renderRight($frame, $this->rightSlot, $contentX, $contentY, $contentWidth, $barBg, $context);
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
    private function leftRect(Rect $contentArea): ?Rect
    {
        if ($this->leftSlot === null) {
            return null;
        }

        if ($this->centerSlot !== null || $this->leftSlot->sizing()->columns->flex === 0) {
            return BarSlotRenderer::rectFor($this->leftSlot, Alignment::Left, $contentArea);
        }

        $right = $this->rightSlot === null ? null : BarSlotRenderer::rectFor($this->rightSlot, Alignment::Right, $contentArea);
        $width = $right === null ? $contentArea->width : $right->x - $contentArea->x - self::SLOT_GAP;

        return $width < 1 ? null : new Rect($contentArea->x, $contentArea->y, $width, 1);
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
