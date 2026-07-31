<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget;

use Elmphant\Tui\Core\Cloneable;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Spacing;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Style;

final readonly class Footer implements Interactive
{
    use Cloneable;

    private function __construct(
        private ?Widget $leftSlot = null,
        private ?Widget $centerSlot = null,
        private ?Widget $rightSlot = null,
        private ?Style $style = null,
        private ?Spacing $padding = null,
        private ?Spacing $margin = null,
    ) {
    }

    public static function new(): self
    {
        return new self();
    }

    /**
     * @param array<string, string> $hints
     */
    public static function hints(array $hints): self
    {
        return new self(centerSlot: StatusBar::hints($hints));
    }

    /**
     * @param array<string, string> $hints
     */
    public static function leftHints(array $hints): self
    {
        return new self(leftSlot: StatusBar::hints($hints));
    }

    /**
     * @param array<string, string> $hints
     */
    public static function rightHints(array $hints): self
    {
        return new self(rightSlot: StatusBar::hints($hints));
    }

    public function left(Widget $widget): self
    {
        return $this->cloneWith(leftSlot: $widget);
    }

    public function center(Widget $widget): self
    {
        return $this->cloneWith(centerSlot: $widget);
    }

    public function right(Widget $widget): self
    {
        return $this->cloneWith(rightSlot: $widget);
    }

    public function style(Style $style): self
    {
        return $this->cloneWith(style: $style);
    }

    public function padding(Spacing $padding): self
    {
        return $this->cloneWith(padding: $padding);
    }

    public function margin(Spacing $margin): self
    {
        return $this->cloneWith(margin: $margin);
    }

    public function constraint(): Constraint
    {
        $padding = $this->padding ?? Spacing::none();
        $margin = $this->margin ?? Spacing::none();

        return Constraint::exact(1 + $padding->top + $padding->bottom + $margin->top + $margin->bottom);
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        $barArea = ($this->margin ?? Spacing::none())->applyTo($area);
        $contentArea = ($this->padding ?? Spacing::none())->applyTo($barArea);

        [$overrides, $command] = BarSlotRenderer::updateSlots([
            'leftSlot' => $this->leftSlot,
            'centerSlot' => $this->centerSlot,
            'rightSlot' => $this->rightSlot,
        ], $message, $contentArea);

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
}
