<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;

/**
 * @internal shared filter bar for FilterableTable and FilterableList
 */
final class FilterBar
{
    public const HEIGHT = 1;

    /**
     * Placeholder, scope label and count, with a gap either side of the label.
     */
    public const MIN_WIDTH = 34;

    public const PLACEHOLDER = 'Filter…';

    private const GAP = 3;

    /**
     * A summary or scope the caller draws elsewhere is passed as null, and the
     * field takes the room back.
     */
    public static function render(
        Frame $frame,
        Rect $area,
        RenderContext $context,
        TextInput $field,
        ?string $summary = null,
        ?string $scope = null,
    ): void {
        $trailing = $summary === null ? 0 : mb_strlen($summary);
        $trailing += $scope === null ? 0 : mb_strlen($scope) + self::GAP;

        $fieldWidth = $area->width - ($trailing === 0 ? 0 : $trailing + self::GAP);

        if ($fieldWidth > 0) {
            $field->render($frame, new Rect($area->x, $area->y, $fieldWidth, self::HEIGHT), $context);
        }

        $summaryX = $area->right() - ($summary === null ? 0 : mb_strlen($summary));
        $scopeX = $summaryX - ($scope === null ? 0 : mb_strlen($scope) + self::GAP);

        if ($scope !== null && $scopeX > $area->x) {
            $frame->putStyled($scopeX, $area->y, $scope, $context->theme->accent);
        }

        if ($summary !== null && $summaryX > $area->x) {
            $frame->putStyled($summaryX, $area->y, $summary, $context->theme->dim);
        }
    }

    public static function field(string $query, bool $focused): TextInput
    {
        return TextInput::new(self::PLACEHOLDER)
            ->withValue($query)
            ->withFocus($focused)
            ->withDecoration($focused ? InputDecoration::Underline : InputDecoration::None);
    }
}
