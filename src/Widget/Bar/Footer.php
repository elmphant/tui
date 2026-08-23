<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Bar;

use Elmphant\Tui\Core\Cloneable;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Input\KeybindingsAware;
use Elmphant\Tui\Input\KeybindingsAwareBehavior;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Layout\Spacing;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Style;
use Elmphant\Tui\Widget\Interactive;
use Elmphant\Tui\Widget\Widget;
use Elmphant\Tui\Widget\WidgetContainer;

final readonly class Footer implements Interactive, WidgetContainer, KeybindingsAware
{
    use Cloneable;
    use KeybindingsAwareBehavior;

    private function __construct(
        private ?Widget $leftSlot = null,
        private ?Widget $centerSlot = null,
        private ?Widget $rightSlot = null,
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
    public function childWidgets(Rect $area): array
    {
        $contentArea = $this->contentArea($area);

        if (null === $contentArea) {
            return [];
        }

        return array_values(BarSlotRenderer::placements($this->slots(), $contentArea));
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        $contentArea = $this->contentArea($area);

        if (null === $contentArea) {
            return new UpdateResult($this);
        }

        [$overrides, $command] = BarSlotRenderer::updateSlots($this->slots(), $message, $contentArea, $this->globalKeybindings);

        if ($overrides !== []) {
            return new UpdateResult($this->cloneWith(...$overrides), $command);
        }

        return new UpdateResult($this, $command);
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        if ($area->width < 1 || $area->height < 1) {
            return;
        }

        $barArea = ($this->margin ?? Spacing::none())->applyTo($area);
        if ($barArea->width < 1 || $barArea->height < 1) {
            return;
        }

        $theme = $context->theme;
        $barStyle = $this->style ?? $theme->footerStyle;
        $barBg = $barStyle->backgroundValue();

        $frame->fillRect($barArea->x, $barArea->y, $barArea->width, $barArea->height, $barStyle->foregroundValue(), $barBg, $barStyle->attributes);

        $contentArea = ($this->padding ?? Spacing::none())->applyTo($barArea);
        if ($contentArea->width < 1 || $contentArea->height < 1) {
            return;
        }

        $contentX = $contentArea->x;
        $contentY = $contentArea->y;
        $contentWidth = $contentArea->width;

        if ($this->leftSlot !== null) {
            BarSlotRenderer::renderLeft($frame, $this->leftSlot, $contentX, $contentY, $contentWidth, $barBg, $context);
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

    private function contentArea(Rect $area): ?Rect
    {
        if ($area->width < 1 || $area->height < 1) {
            return null;
        }

        $barArea = ($this->margin ?? Spacing::none())->applyTo($area);

        if ($barArea->width < 1 || $barArea->height < 1) {
            return null;
        }

        $contentArea = ($this->padding ?? Spacing::none())->applyTo($barArea);

        return $contentArea->width < 1 || $contentArea->height < 1 ? null : $contentArea;
    }
}
