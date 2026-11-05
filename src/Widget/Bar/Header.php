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
use Elmphant\Tui\Text\CharWidth;
use Elmphant\Tui\Widget\Alignment;
use Elmphant\Tui\Widget\Interactive;
use Elmphant\Tui\Widget\Widget;
use Elmphant\Tui\Widget\WidgetContainer;

final readonly class Header implements Interactive, WidgetContainer, KeybindingsAware
{
    use Cloneable;
    use KeybindingsAwareBehavior;
    private const TITLE_GAP = 1;
    private const SLOT_GAP = 2;

    /**
     * @param list<Widget> $shorterRight
     */
    private function __construct(
        private string $title = '',
        private ?Widget $leftSlot = null,
        private ?Widget $centerSlot = null,
        private ?Widget $rightSlot = null,
        private ?Alignment $markedSlot = null,
        private ?Style $style = null,
        private ?Spacing $padding = null,
        private ?Spacing $margin = null,
        private bool $rule = false,
        private ?Keybindings $customKeybindings = null,
        private ?Keybindings $globalKeybindings = null,
        private array $shorterRight = [],
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

    /**
     * Choose the longest right-slot form that leaves room for the left slot; hide it if none fits.
     */
    public function withRight(Widget $widget, Widget ...$shorter): self
    {
        return $this->cloneWith(rightSlot: $widget, shorterRight: array_values($shorter));
    }

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

    public function withRule(bool $rule = true): self
    {
        return $this->cloneWith(rule: $rule);
    }

    public function sizing(): Sizing
    {
        $padding = $this->padding ?? Spacing::none();
        $margin = $this->margin ?? Spacing::none();
        $ruleHeight = $this->rule ? 1 : 0;

        return Sizing::of(
            Constraint::flex(),
            Constraint::exact(1 + $padding->top + $padding->bottom + $margin->top + $margin->bottom + $ruleHeight),
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

        $right = $this->rightRectIn($contentArea);
        $placed = BarSlotRenderer::placements([...$this->slots(), 'rightSlot' => null], $contentArea);

        return [
            ...array_values($placed),
            ...($this->rightSlot === null || $right === null ? [] : [[$this->rightSlot, $right]]),
        ];
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        $contentArea = $this->contentArea($area);

        if (null === $contentArea) {
            return new UpdateResult($this);
        }

        [$overrides, $command] = BarSlotRenderer::updateSlots(
            $this->slots(),
            $message,
            $contentArea,
            $this->markedSlot,
            $this->globalKeybindings,
            drawnAt: ['rightSlot' => $this->rightRectIn($contentArea)],
        );

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

        $leftRect = $this->leftSlot !== null ? BarSlotRenderer::rectFor($this->leftSlot, Alignment::Left, $contentArea, $context) : null;
        $right = $this->rightDrawn($contentArea, $context);
        $rightRect = $right !== null ? BarSlotRenderer::rectFor($right, Alignment::Right, $contentArea, $context) : null;
        $centreRect = $this->centerSlot !== null ? BarSlotRenderer::centreBetween($this->centerSlot, $contentArea, $leftRect, $rightRect, self::SLOT_GAP, $context) : null;

        if ($this->leftSlot !== null) {
            BarSlotRenderer::drawIn($frame, $this->leftSlot, $leftRect, $barBg, $context);
        }

        if ($this->centerSlot !== null) {
            BarSlotRenderer::drawIn($frame, $this->centerSlot, $centreRect, $barBg, $context);
        }

        // Centre the default title unless a centre slot exists; then move it left unless an explicit left slot replaces it.
        $titleWidth = CharWidth::stringWidth($this->title);

        if ($titleWidth > 0 && ($this->centerSlot === null || $this->leftSlot === null)) {
            $gapEnd = $contentX + $contentWidth;

            if ($centreRect !== null) {
                $gapEnd = min($gapEnd, $centreRect->x);
            }

            if ($rightRect !== null) {
                $gapEnd = min($gapEnd, $rightRect->x - self::TITLE_GAP);
            }

            $leftEdge = $leftRect?->right() ?? $contentX;
            $gapStart = $this->centerSlot === null
                ? max($leftEdge, min($contentX + intdiv($contentWidth - $titleWidth, 2), $gapEnd - $titleWidth))
                : $leftEdge;

            $available = $gapEnd - $gapStart;
            if ($available > 0) {
                $frame->putString($gapStart, $contentY, CharWidth::truncateToWidth($this->title, $available), $headerStyle->foregroundValue(), $barBg, $headerStyle->attributes);
            }
        }

        if ($right !== null) {
            BarSlotRenderer::drawIn($frame, $right, $rightRect, $barBg, $context);
        }

        if ($this->rule) {
            $ruleY = $barArea->y + $barArea->height;
            if ($ruleY < $area->y + $area->height) {
                $ruleStyle = $context->theme->borderStyle;
                $frame->putString($barArea->x, $ruleY, str_repeat('─', $barArea->width), $ruleStyle->foregroundValue(), $ruleStyle->backgroundValue());
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

    private function rightRectIn(Rect $contentArea): ?Rect
    {
        $right = $this->rightDrawn($contentArea);

        return $right === null ? null : BarSlotRenderer::rectFor($right, Alignment::Right, $contentArea);
    }

    private function rightDrawn(Rect $contentArea, ?RenderContext $context = null): ?Widget
    {
        if ($this->rightSlot === null || $this->leftSlot === null) {
            return $this->rightSlot;
        }

        $left = BarSlotRenderer::rectFor($this->leftSlot, Alignment::Left, new Rect($contentArea->x, $contentArea->y, \PHP_INT_MAX, 1), $context);
        $room = $contentArea->width - ($left === null ? 0 : $left->width + self::SLOT_GAP);

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
        $innerArea = $area->inset($this->margin ?? Spacing::none());

        if ($innerArea->width < 1 || $innerArea->height < 1) {
            return null;
        }

        $padding = $this->padding ?? Spacing::none();
        $barHeight = 1 + $padding->top + $padding->bottom;
        $barArea = new Rect($innerArea->x, $innerArea->y, $innerArea->width, min($barHeight, $innerArea->height));
        $contentArea = $barArea->inset($padding);

        return [
            'bar' => $barArea,
            'content' => $contentArea->width > 0 && $contentArea->height > 0 ? $contentArea : null,
        ];
    }
}
