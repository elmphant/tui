<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Field;

use Elmphant\Tui\Text\GraphemeIndex;

/**
 * One line of text counted in graphemes and display columns, for the fields that edit text.
 *
 * @internal
 */
final class LineText
{
    public static function length(string $text): int
    {
        return self::isPlain($text) ? strlen($text) : GraphemeIndex::of($text)->count;
    }

    public static function substring(string $text, int $offset, ?int $length = null): string
    {
        if (self::isPlain($text)) {
            return substr($text, $offset, $length);
        }

        $index = GraphemeIndex::of($text);
        $first = min($offset, $index->count);
        $end = $length === null ? $index->count : min($first + $length, $index->count);

        return substr($text, $index->byteOf($first), $index->byteOf($end) - $index->byteOf($first));
    }

    public static function width(string $text): int
    {
        return self::isPlain($text) ? strlen($text) : GraphemeIndex::of($text)->width();
    }

    public static function prefixWidth(string $text, int $graphemes): int
    {
        if (self::isPlain($text)) {
            return min($graphemes, strlen($text));
        }

        $index = GraphemeIndex::of($text);

        return $index->columnOf(min($graphemes, $index->count));
    }

    /**
     * The grapheme a click at this column lands on.
     */
    public static function graphemeAt(string $text, int $column): int
    {
        return self::isPlain($text)
            ? min($column, strlen($text))
            : GraphemeIndex::of($text)->lastUpTo($column);
    }

    /**
     * Drawing skips a wide character as a whole, so the caret and clicks must count from where it ends too.
     */
    public static function columnAtOrAfter(string $text, int $column): int
    {
        if (self::isPlain($text)) {
            return min($column, strlen($text));
        }

        $index = GraphemeIndex::of($text);

        return $index->columnOf($index->firstFrom($column));
    }

    public static function slice(string $text, int $offsetColumns, int $maxColumns): string
    {
        if (self::isPlain($text)) {
            return substr($text, $offsetColumns, $maxColumns);
        }

        $index = GraphemeIndex::of($text);
        $first = $index->firstFrom($offsetColumns);
        $end = $index->lastUpTo($index->columnOf($first) + $maxColumns);

        return substr($text, $index->byteOf($first), $index->byteOf($end) - $index->byteOf($first));
    }

    /**
     * @param list<string> $graphemes
     */
    public static function wordStartBefore(array $graphemes, int $cursor): int
    {
        while ($cursor > 0 && self::wordKindOf($graphemes[$cursor - 1]) === 'space') {
            --$cursor;
        }

        $kind = $cursor > 0 ? self::wordKindOf($graphemes[$cursor - 1]) : null;
        while ($cursor > 0 && self::wordKindOf($graphemes[$cursor - 1]) === $kind) {
            --$cursor;
        }

        return $cursor;
    }

    /**
     * @param list<string> $graphemes
     */
    public static function wordEndAfter(array $graphemes, int $cursor): int
    {
        $length = count($graphemes);
        while ($cursor < $length && self::wordKindOf($graphemes[$cursor]) === 'space') {
            ++$cursor;
        }

        $kind = $cursor < $length ? self::wordKindOf($graphemes[$cursor]) : null;
        while ($cursor < $length && self::wordKindOf($graphemes[$cursor]) === $kind) {
            ++$cursor;
        }

        return $cursor;
    }

    /**
     * In printable ASCII every byte is one grapheme one column wide, so byte offsets are the answer.
     *
     * @infection-ignore-all a memo of the last answer: the same without it, only slower
     */
    public static function isPlain(string $text): bool
    {
        static $last = null;
        static $answer = false;
        if ($text !== $last) {
            $last = $text;
            $answer = preg_match('/[^\x20-\x7e]/', $text) !== 1;
        }

        return $answer;
    }

    /**
     * @return 'space'|'word'|'punctuation'
     */
    private static function wordKindOf(string $grapheme): string
    {
        return match (true) {
            preg_match('/^\s/u', $grapheme) === 1 => 'space',
            preg_match('/^[\p{L}\p{N}\p{M}_]/u', $grapheme) === 1 => 'word',
            default => 'punctuation',
        };
    }
}
