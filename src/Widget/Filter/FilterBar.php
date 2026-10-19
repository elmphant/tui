<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Filter;

use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Search\Matcher;
use Elmphant\Tui\Search\SubstringMatcher;
use Elmphant\Tui\Text\CharWidth;
use Elmphant\Tui\Widget\Field\TextInput;

/**
 * @internal what FilterableList, FilterableCheckboxGroup and FilterableTable share:
 * the bar's place, its field, its cancel key and how a query narrows the entries
 */
final class FilterBar
{
    public const HEIGHT = 1;

    /**
     * Placeholder and count, with a gap between them.
     */
    public const MIN_WIDTH = 20;

    public const PLACEHOLDER = 'Filter…';

    /**
     * The blank row a list of choices leaves under the field.
     */
    public const GAP_BELOW = 1;

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
        $trailing = $summary === null ? 0 : CharWidth::stringWidth($summary);
        $fieldWidth = $area->width - ($trailing === 0 ? 0 : $trailing + self::GAP);

        if ($fieldWidth > 0) {
            $field->render($frame, new Rect($area->x, $area->y, $fieldWidth, self::HEIGHT), $context);
        }

        $summaryX = $area->right() - $trailing;

        if ($summary !== null && $summaryX >= $area->x) {
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
        return new Keybindings([Actions::CANCEL => ['esc']]);
    }

    /**
     * The keys that hand the keys back from a search, bound only while the field
     * holds them, so the content never lists an Enter it has no use for.
     */
    public static function backKeybindings(): Keybindings
    {
        return new Keybindings([Actions::LEAVE_SEARCH => ['esc', 'enter']]);
    }

    public static function field(string $query): TextInput
    {
        return TextInput::new(self::PLACEHOLDER)->withValue($query);
    }

    public static function barArea(Rect $area, ?int $width = null): Rect
    {
        return new Rect($area->x, $area->y, $width ?? $area->width, self::HEIGHT);
    }

    public static function bodyArea(Rect $area, int $gap = 0): Rect
    {
        $top = self::HEIGHT + $gap;

        return new Rect($area->x, $area->y + $top, $area->width, max(0, $area->height - $top));
    }

    /**
     * A query of nothing keeps everything, in the order it was handed in. That
     * is not a matcher's answer to work out — asking one would cost a pass over
     * the whole list on every keystroke back to empty.
     *
     * @param list<string> $haystack
     *
     * @return list<int>
     */
    public static function matching(?Matcher $matcher, string $query, array $haystack): array
    {
        if (trim($query) === '') {
            return array_keys($haystack);
        }

        // A matcher may name the same entry twice — nothing in the port forbids
        // it — and an index past the entries names nothing at all.
        return array_values(array_unique(array_filter(
            ($matcher ?? new SubstringMatcher())->matching($query, $haystack),
            static fn (int $index): bool => isset($haystack[$index]),
        )));
    }
}
