<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Runtime\ChildDispatcher;

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
     * Route a message to whichever bar slots are interactive.
     *
     * @param array{leftSlot: ?Widget, centerSlot: ?Widget, rightSlot: ?Widget} $slots keyed by the constructor property to override
     *
     * @return array{array<string, Interactive>, ?Cmd}
     */
    public static function updateSlots(array $slots, Message $message, Rect $contentArea): array
    {
        /** @var array<string, Interactive> $interactive */
        $interactive = array_filter($slots, static fn (?Widget $slot): bool => $slot instanceof Interactive);

        $areas = [];
        foreach ($interactive as $key => $slot) {
            // A slot with no width of its own is still on the bar and still takes
            // keys; only a click has nowhere to land.
            $areas[$key] = self::rectFor($slot, self::ALIGNMENTS[$key], $contentArea)
                ?? new Rect($contentArea->x, $contentArea->y, 0, 1);
        }

        $result = (new ChildDispatcher())->dispatchMap($interactive, $areas, $message);

        return [$result->overrides, $result->command];
    }

    /**
     * A bar is not an allocator: it reads the width the slot declares and never
     * grows it by its flex weight, which would stretch every slot to the whole
     * row and leave nothing for the centre and right to align against.
     */
    private static function rectFor(Widget $widget, Alignment $alignment, Rect $contentArea): ?Rect
    {
        $columns = $widget->sizing()->columns;
        $width = min($columns->basis ?? $columns->min, $contentArea->width);

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
        $rect = self::rectFor($widget, $alignment, new Rect($contentX, $contentY, $contentWidth, 1));

        if (null === $rect) {
            return;
        }

        $frame->pushClip($rect);
        $widget->render($frame, $rect, $context);
        $frame->popClip();

        self::keepBarBackground($frame, $rect, $barBackground);
    }

    /**
     * Frame writes carry a background unconditionally, so a slot styled without
     * one would punch its own text out of the bar's fill.
     */
    private static function keepBarBackground(Frame $frame, Rect $rect, int $barBackground): void
    {
        if (0 === $barBackground || $rect->y < 0 || $rect->y >= $frame->height) {
            return;
        }

        $rowBase = $rect->y * $frame->width;

        for ($x = max(0, $rect->x); $x < min($frame->width, $rect->right()); ++$x) {
            $index = $rowBase + $x;
            if (0 === $frame->backgrounds[$index]) {
                $frame->backgrounds[$index] = $barBackground;
                $frame->markRowDirty($rect->y);
            }
        }
    }
}
