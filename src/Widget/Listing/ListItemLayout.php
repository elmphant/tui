<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Listing;

use Elmphant\Tui\Text\CharWidth;
use Elmphant\Tui\Text\TextWrap;

/**
 * How a list item occupies rows at a given width.
 *
 * Height and rendering share this one implementation: if they disagreed, the
 * list's scroll offsets and click targets would drift away from what is drawn.
 */
final class ListItemLayout
{
    /**
     * Descriptions are indented under their title.
     */
    public const DESCRIPTION_INDENT = 2;

    /**
     * Rows one item may occupy.
     *
     * Wrapping without a cap lets a single item fill a narrow pane, which turns
     * a list into a paragraph. Past the cap the last row is ellipsized, so the
     * row stays honest about having more text.
     */
    public const MAX_ROWS = 4;

    /**
     * The visual rows of one item: the wrapped title, then the wrapped
     * description.
     *
     * @return list<array{text: string, indent: int, isDescription: bool}>
     */
    public static function rows(ListItem $item, int $width): array
    {
        if ($width < 1) {
            return [['text' => '', 'indent' => 0, 'isDescription' => false]];
        }

        $rows = [];

        foreach (TextWrap::wrap($item->title(), $width) as $line) {
            $rows[] = ['text' => $line, 'indent' => 0, 'isDescription' => false];
        }

        $description = $item->description();

        if ($description !== '') {
            $descriptionWidth = max(1, $width - self::DESCRIPTION_INDENT);

            foreach (TextWrap::wrap($description, $descriptionWidth) as $line) {
                $rows[] = ['text' => $line, 'indent' => self::DESCRIPTION_INDENT, 'isDescription' => true];
            }
        }

        return self::capped($rows, $width);
    }

    public static function height(ListItem $item, int $width): int
    {
        if ($width >= 1 && $item->description() === '' && CharWidth::stringWidth($item->title()) <= $width) {
            return 1;
        }

        return count(self::rows($item, $width));
    }

    /**
     * @param list<array{text: string, indent: int, isDescription: bool}> $rows
     *
     * @return list<array{text: string, indent: int, isDescription: bool}>
     */
    private static function capped(array $rows, int $width): array
    {
        if (count($rows) <= self::MAX_ROWS) {
            return $rows;
        }

        $kept = array_slice($rows, 0, self::MAX_ROWS);
        $last = $kept[self::MAX_ROWS - 1];
        $available = max(1, $width - $last['indent']);

        $last['text'] = TextWrap::ellipsize($last['text'] . ' ' . $rows[self::MAX_ROWS]['text'], $available);
        $kept[self::MAX_ROWS - 1] = $last;

        return $kept;
    }
}
