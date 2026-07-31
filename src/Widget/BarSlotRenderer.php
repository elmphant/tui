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
 * @internal shared slot rendering and routing for the Header and Footer bars
 */
final class BarSlotRenderer
{
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
     * @param array<string, ?Widget> $slots keyed by the constructor property to override
     *
     * @return array{array<string, Interactive>, ?Cmd}
     */
    public static function updateSlots(array $slots, Message $message, Rect $contentArea): array
    {
        /** @var array<string, Interactive> $interactive */
        $interactive = array_filter($slots, static fn (?Widget $slot): bool => $slot instanceof Interactive);
        $result = (new ChildDispatcher())->dispatchMap(
            $interactive,
            array_fill_keys(array_keys($interactive), $contentArea),
            $message,
        );

        return [$result->overrides, $result->command];
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
        $tempBuffer = $context->scratchBuffer($contentWidth, 1);
        $widget->render($tempBuffer, Rect::fromSize($contentWidth, 1), $context);

        $renderedWidth = self::measureRenderedWidth($tempBuffer, $contentWidth);
        if ($renderedWidth === 0) {
            return;
        }

        $offsetX = match ($alignment) {
            Alignment::Left => $contentX,
            Alignment::Right => $contentX + $contentWidth - $renderedWidth,
            Alignment::Center => max($contentX, $contentX + (int)floor(($contentWidth - $renderedWidth) / 2)),
        };

        self::copyWithBackground($tempBuffer, $frame, 0, $offsetX, $contentY, $renderedWidth, $barBackground);
    }

    /**
     * Where the slot's text ends.
     *
     * Measured by character alone: a widget that paints its style across the
     * whole row — StatusBar does — leaves every trailing cell carrying a colour,
     * and counting those as content made the slot measure full width, collapsing
     * the centre and right offsets to zero.
     */
    private static function measureRenderedWidth(Frame $tempBuffer, int $maxWidth): int
    {
        for ($x = $maxWidth - 1; $x >= 0; --$x) {
            if ($tempBuffer->characters[$x] !== ' ' && $tempBuffer->characters[$x] !== '') {
                return $x + 1;
            }
        }

        return 0;
    }

    private static function copyWithBackground(
        Frame $source,
        Frame $destination,
        int $sourceStartX,
        int $destinationX,
        int $destinationY,
        int $width,
        int $fallbackBackground,
    ): void {
        for ($x = 0; $x < $width; ++$x) {
            $sourceIndex = $sourceStartX + $x;
            $destX = $destinationX + $x;
            if ($destX < 0 || $destX >= $destination->width) {
                continue;
            }
            $destIndex = $destinationY * $destination->width + $destX;
            $destination->characters[$destIndex] = $source->characters[$sourceIndex];
            $destination->foregrounds[$destIndex] = $source->foregrounds[$sourceIndex];
            $destination->backgrounds[$destIndex] = $source->backgrounds[$sourceIndex] !== 0
                ? $source->backgrounds[$sourceIndex]
                : $fallbackBackground;
            $destination->attributes[$destIndex] = $source->attributes[$sourceIndex];
        }
        $destination->markRowDirty($destinationY);
    }
}
