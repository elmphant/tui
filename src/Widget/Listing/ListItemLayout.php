<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Listing;

use Elmphant\Tui\Text\CharWidth;
use Elmphant\Tui\Text\TextWrap;

/**
 * Share measurement and rendering rules so scroll offsets and click targets match the drawn rows.
 */
final class ListItemLayout
{
    public const DESCRIPTION_INDENT = 2;

    /**
     * Cap wrapped rows so one item cannot fill a narrow pane; mark omitted text with an ellipsis.
     */
    public const MAX_ROWS = 4;

    /**
     * @return list<array{text: string, indent: int, isDescription: bool}>
     */
    public static function rows(ListItem $item, int $width): array
    {
        if ($width < 1) {
            return [['text' => '', 'indent' => 0, 'isDescription' => false]];
        }

        $rows = [];
        $title = ltrim($item->title(), ' ');
        $indent = min(\strlen($item->title()) - \strlen($title), $width - 1);

        foreach (TextWrap::wrap($title, $width - $indent) as $line) {
            $rows[] = ['text' => $line, 'indent' => $indent, 'isDescription' => false];
        }

        $description = $item->description();

        if ($description !== '') {
            $descriptionIndent = $indent + self::DESCRIPTION_INDENT;

            foreach (TextWrap::wrap($description, max(1, $width - $descriptionIndent)) as $line) {
                $rows[] = ['text' => $line, 'indent' => $descriptionIndent, 'isDescription' => true];
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
