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
use Elmphant\Tui\Text\WrapCache;
use Elmphant\Tui\Widget\Field\TextInput;
use Throwable;

/**
 * @internal what FilterableList, FilterableCheckboxGroup and FilterableStaticTable share:
 * the bar's place, its field, its cancel key and how a query narrows the entries
 */
final class FilterBar
{
    public const HEIGHT = 1;

    public const MIN_WIDTH = 20;

    public const PLACEHOLDER = 'Filter…';

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
     * Escape clears a non-empty query; an empty filter must leave it unhandled so the host can respond.
     */
    public static function keybindings(): Keybindings
    {
        return new Keybindings([Actions::CANCEL => ['esc']]);
    }

    /**
     * Bind search-exit keys only while searching, so content hints do not advertise an unused Enter key.
     */
    public static function backKeybindings(): Keybindings
    {
        return new Keybindings([Actions::LEAVE_SEARCH => ['esc', 'enter']]);
    }

    public static function field(string $query): TextInput
    {
        return TextInput::new($query)->withPlaceholder(self::PLACEHOLDER);
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
     * Empty queries keep all entries without calling the matcher; cache other results by matcher, query and entries.
     * If the matcher cannot be serialized, run it each time.
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

        $matcher ??= new SubstringMatcher();
        // Drop duplicate and out-of-range indices returned by matchers.
        $search = static fn (): array => array_values(array_unique(array_filter(
            $matcher->matching($query, $haystack),
            static fn (int $index): bool => isset($haystack[$index]),
        )));

        try {
            $key = hash('xxh3', serialize([$matcher, $query, $haystack]));
        } catch (Throwable) {
            return $search();
        }

        /** @var list<int> */
        return WrapCache::remember('matches:' . $key, $search);
    }
}
