<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Filter;

use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Widget\Field\InputDecoration;
use Elmphant\Tui\Widget\Field\TextInput;

/**
 * @internal shared filter bar for FilterableTable and FilterableList
 */
final class FilterBar
{
    public const HEIGHT = 1;

    /**
     * Placeholder and count, with a gap between them.
     */
    public const MIN_WIDTH = 20;

    public const PLACEHOLDER = 'Filter…';

    private const GAP = 3;

    /**
     * A summary the caller draws elsewhere is passed as null, and the field
     * takes the room back.
     */
    public static function render(
        Frame $frame,
        Rect $area,
        RenderContext $context,
        TextInput $field,
        ?string $summary = null,
    ): void {
        $trailing = $summary === null ? 0 : mb_strlen($summary);
        $fieldWidth = $area->width - ($trailing === 0 ? 0 : $trailing + self::GAP);

        if ($fieldWidth > 0) {
            $field->render($frame, new Rect($area->x, $area->y, $fieldWidth, self::HEIGHT), $context);
        }

        $summaryX = $area->right() - $trailing;

        if ($summary !== null && $summaryX > $area->x) {
            $frame->putStyled($summaryX, $area->y, $summary, $context->theme->dim);
        }
    }

    /**
     * The key every filter in the family cancels on. A filter with something in it
     * clears; an empty one must return itself rather than hand the key to its field,
     * or a host above never learns the escape went spare.
     */
    public static function keybindings(): Keybindings
    {
        static $instance = null;

        return $instance ??= new Keybindings([Actions::CANCEL => ['esc']]);
    }

    public static function field(string $query, bool $focused): TextInput
    {
        return TextInput::new(self::PLACEHOLDER)
            ->withValue($query)
            ->withFocus($focused)
            ->withDecoration($focused ? InputDecoration::Underline : InputDecoration::None);
    }
}
