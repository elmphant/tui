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
use Elmphant\Tui\Rendering\CharWidth;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Style;
use Elmphant\Tui\Widget\Alignment;
use Elmphant\Tui\Widget\Interactive;
use Elmphant\Tui\Widget\Widget;
use Elmphant\Tui\Widget\WidgetContainer;

final readonly class Header implements Interactive, WidgetContainer, KeybindingsAware
{
    use Cloneable;
    use KeybindingsAwareBehavior;

    private function __construct(
        private string $title = '',
        private ?Widget $leftSlot = null,
        private ?Widget $centerSlot = null,
        private ?Widget $rightSlot = null,
        private ?Alignment $markedSlot = null,
        private ?Style $style = null,
        private ?Spacing $padding = null,
        private ?Spacing $margin = null,
        private bool $divider = false,
        private ?Keybindings $customKeybindings = null,
        private ?Keybindings $globalKeybindings = null,
    ) {
    }

    public static function new(string $title = ''): self
    {
        return new self($title);
    }

    public static function defaultKeybindings(): Keybindings
    {
        return Keybindings::empty();
    }

    public function withTitle(string $title): self
    {
        return $this->cloneWith(title: $title);
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

    public function withDivider(bool $divider = true): self
    {
        return $this->cloneWith(divider: $divider);
    }

    public function sizing(): Sizing
    {
        $padding = $this->padding ?? Spacing::none();
        $margin = $this->margin ?? Spacing::none();
        $dividerHeight = $this->divider ? 1 : 0;

        return Sizing::of(
            Constraint::flex(),
            Constraint::exact(1 + $padding->top + $padding->bottom + $margin->top + $margin->bottom + $dividerHeight),
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

        return array_values(BarSlotRenderer::placements($this->slots(), $contentArea));
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
        $contentArea = $geometry['content'];

        $headerStyle = $this->style ?? $context->theme->headerStyle;
        $barBg = $headerStyle->backgroundValue();
        $frame->fillRect($barArea->x, $barArea->y, $barArea->width, $barArea->height, $headerStyle->foregroundValue(), $barBg, $headerStyle->attributes);

        $contentArea = $geometry['content'];

        if ($contentArea === null) {
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

        // The title is the bar's default content rather than a fourth slot: it
        // centres while the centre is free, and steps left when a centre slot
        // takes that place. Only an explicit left slot displaces it entirely —
        // a caller-supplied title vanishing is never the useful answer.
        $titleWidth = CharWidth::stringWidth($this->title);

        if ($titleWidth > 0 && ($this->centerSlot === null || $this->leftSlot === null)) {
            $gapStart = $this->centerSlot === null
                ? max($contentX, $contentX + (int)floor(($contentWidth - $titleWidth) / 2))
                : $contentX;
            $gapEnd = $contentX + $contentWidth;

            $leftRect = $this->leftSlot !== null ? BarSlotRenderer::rectFor($this->leftSlot, Alignment::Left, $contentArea) : null;

            if ($leftRect !== null) {
                $gapStart = max($gapStart, $leftRect->right());
            }

            $centreRect = $this->centerSlot !== null ? BarSlotRenderer::rectFor($this->centerSlot, Alignment::Center, $contentArea) : null;

            if ($centreRect !== null) {
                $gapEnd = min($gapEnd, $centreRect->x);
            }

            $available = $gapEnd - $gapStart;
            if ($available > 0) {
                $frame->putString($gapStart, $contentY, CharWidth::truncateToWidth($this->title, $available), $headerStyle->foregroundValue(), $barBg, $headerStyle->attributes);
            }
        }

        if ($this->rightSlot !== null) {
            BarSlotRenderer::renderRight($frame, $this->rightSlot, $contentX, $contentY, $contentWidth, $barBg, $context);
        }

        if ($this->divider) {
            $dividerY = $barArea->y + $barArea->height;
            if ($dividerY < $area->y + $area->height) {
                $dividerStyle = $context->theme->borderStyle;
                $frame->putString($barArea->x, $dividerY, str_repeat('─', $barArea->width), $dividerStyle->foregroundValue(), $dividerStyle->backgroundValue());
            }
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
        $barHeight = 1 + $padding->top + $padding->bottom;
        $barArea = new Rect($innerArea->x, $innerArea->y, $innerArea->width, min($barHeight, $innerArea->height));
        $contentArea = $padding->applyTo($barArea);

        return [
            'bar' => $barArea,
            'content' => $contentArea->width > 0 && $contentArea->height > 0 ? $contentArea : null,
        ];
    }
}
