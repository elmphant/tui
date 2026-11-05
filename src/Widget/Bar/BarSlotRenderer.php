<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Bar;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\PasteMsg;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Widget\Alignment;
use Elmphant\Tui\Widget\Dispatch\ChildDispatcher;
use Elmphant\Tui\Widget\Interactive;
use Elmphant\Tui\Widget\Widget;

/**
 * @internal shared slot placement, drawing and routing for the Header and Footer bars
 */
final class BarSlotRenderer
{
    private const ALIGNMENTS = [
        'leftSlot' => Alignment::Left,
        'centerSlot' => Alignment::Center,
        'rightSlot' => Alignment::Right,
    ];

    public static function renderLeft(
        Frame $frame,
        Widget $widget,
        int $contentX,
        int $contentY,
        int $contentWidth,
        int $barBackground,
        RenderContext $context,
    ): void {
        self::render($frame, $widget, Alignment::Left, $contentX, $contentY, $contentWidth, $barBackground, $context);
    }

    public static function renderRight(
        Frame $frame,
        Widget $widget,
        int $contentX,
        int $contentY,
        int $contentWidth,
        int $barBackground,
        RenderContext $context,
    ): void {
        self::render($frame, $widget, Alignment::Right, $contentX, $contentY, $contentWidth, $barBackground, $context);
    }

    public static function renderCentered(
        Frame $frame,
        Widget $widget,
        int $contentX,
        int $contentY,
        int $contentWidth,
        int $barBackground,
        RenderContext $context,
    ): void {
        self::render($frame, $widget, Alignment::Center, $contentX, $contentY, $contentWidth, $barBackground, $context);
    }

    /**
     * @param array{leftSlot: ?Widget, centerSlot: ?Widget, rightSlot: ?Widget} $slots
     *
     * @return array<string, array{Widget, Rect}> only the slots with a width to draw in
     */
    public static function placements(array $slots, Rect $contentArea): array
    {
        $placements = [];

        foreach (self::ALIGNMENTS as $key => $alignment) {
            $slot = $slots[$key];

            if (null === $slot) {
                continue;
            }

            $rect = self::rectFor($slot, $alignment, $contentArea);

            if (null !== $rect) {
                $placements[$key] = [$slot, $rect];
            }
        }

        return $placements;
    }

    /**
     * @param array{leftSlot: ?Widget, centerSlot: ?Widget, rightSlot: ?Widget} $slots   keyed by the constructor property to override
     * @param array<string, ?Rect>                                              $drawnAt where a slot is drawn somewhere other than its own width puts it
     *
     * @return array{array<string, Interactive>, ?Cmd}
     */
    public static function updateSlots(array $slots, Message $message, Rect $contentArea, ?Alignment $markedSlot = null, ?Keybindings $globalKeybindings = null, array $drawnAt = []): array
    {
        /** @var array<string, Interactive> $interactive */
        $interactive = array_filter($slots, static fn (?Widget $slot): bool => $slot instanceof Interactive);

        $areas = [];
        foreach ($interactive as $key => $slot) {
            // Zero-width slots can still receive keys, but have no area to click.
            $areas[$key] = (\array_key_exists($key, $drawnAt) ? $drawnAt[$key] : self::rectFor($slot, self::ALIGNMENTS[$key], $contentArea))
                ?? new Rect($contentArea->x, $contentArea->y, 0, 1);
        }

        if ($message instanceof KeyMsg || $message instanceof PasteMsg) {
            $interactive = self::marked($interactive, $markedSlot);
        }

        $result = (new ChildDispatcher($globalKeybindings))->dispatchMap($interactive, $areas, $message);

        return [$result->overrides, $result->command];
    }

    /**
     * Do not flex slots: stretching them would leave no space for centre and right alignment.
     */
    public static function rectFor(Widget $widget, Alignment $alignment, Rect $contentArea, ?RenderContext $context = null): ?Rect
    {
        $wanted = $widget instanceof FocusHints && null !== $context
            ? $widget->widthIn($context)
            : $widget->sizing()->columns->intrinsic();
        $width = min($wanted, $contentArea->width);

        if ($width < 1) {
            return null;
        }

        $offsetX = match ($alignment) {
            Alignment::Left => $contentArea->x,
            Alignment::Right => $contentArea->x + $contentArea->width - $width,
            Alignment::Center => $contentArea->x + intdiv($contentArea->width - $width, 2),
        };

        return new Rect($offsetX, $contentArea->y, $width, 1);
    }

    /**
     * Centred on the bar where it fits, else moved or cut into the room the side slots leave.
     */
    public static function centreBetween(Widget $widget, Rect $contentArea, ?Rect $left, ?Rect $right, int $gap, RenderContext $context): ?Rect
    {
        $centred = self::rectFor($widget, Alignment::Center, $contentArea, $context);
        if (null === $centred) {
            return null;
        }

        $from = null === $left ? $contentArea->x : $left->right() + $gap;
        $to = null === $right ? $contentArea->right() : $right->x - $gap;
        $width = min($centred->width, $to - $from);
        if ($width < 1) {
            return null;
        }

        return new Rect(max($from, min($centred->x, $to - $width)), $contentArea->y, $width, 1);
    }

    public static function drawIn(Frame $frame, Widget $widget, ?Rect $rect, int $barBackground, RenderContext $context): void
    {
        if (null === $rect) {
            return;
        }

        $frame->clipped($rect, static fn () => $context->draw($widget, $frame, $rect));

        self::keepBarBackground($frame, $rect, $barBackground, $context->theme->canvas->value ?? 0);
    }

    /**
     * Send text only to the slot the host marked as focused; bars do not cycle focus.
     *
     * @param array<string, Interactive> $slots
     *
     * @return array<string, Interactive>
     */
    private static function marked(array $slots, ?Alignment $markedSlot): array
    {
        foreach ($slots as $key => $slot) {
            if (self::ALIGNMENTS[$key] === $markedSlot) {
                return [$key => $slot];
            }
        }

        return [];
    }

    private static function render(
        Frame $frame,
        Widget $widget,
        Alignment $alignment,
        int $contentX,
        int $contentY,
        int $contentWidth,
        int $barBackground,
        RenderContext $context,
    ): void {
        self::drawIn($frame, $widget, self::rectFor($widget, $alignment, new Rect($contentX, $contentY, $contentWidth, 1), $context), $barBackground, $context);
    }

    /**
     * Use the bar background for slot text so a missing or canvas background cannot overwrite the bar's fill.
     */
    private static function keepBarBackground(Frame $frame, Rect $rect, int $barBackground, int $page): void
    {
        if (0 === $barBackground || $rect->y < 0 || $rect->y >= $frame->height) {
            return;
        }

        $rowBase = $rect->y * $frame->width;

        for ($x = max(0, $rect->x); $x < min($frame->width, $rect->right()); ++$x) {
            $index = $rowBase + $x;
            if (0 === $frame->backgrounds[$index] || $page === $frame->backgrounds[$index]) {
                $frame->backgrounds[$index] = $barBackground;
                $frame->markRowDirty($rect->y);
            }
        }
    }
}
