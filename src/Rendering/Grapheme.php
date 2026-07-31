<?php

declare(strict_types=1);

namespace Elmphant\Tui\Rendering;

/**
 * @internal
 */
final class Grapheme
{
    /**
     * Bytes in the UTF-8 sequence a lead byte opens, 1 for anything that opens
     * none — a single-byte character, a stray continuation byte, or an invalid
     * lead byte, all of which advance a scanner by one.
     */
    public static function sequenceLength(int $byte): int
    {
        return match (true) {
            ($byte & 0xE0) === 0xC0 => 2,
            ($byte & 0xF0) === 0xE0 => 3,
            ($byte & 0xF8) === 0xF0 => 4,
            default => 1,
        };
    }

    /**
     * @return list<string>
     */
    public static function split(string $string): array
    {
        $result = [];
        $offset = 0;
        $length = strlen($string);

        while ($offset < $length) {
            $grapheme = grapheme_extract($string, 1, GRAPHEME_EXTR_COUNT, $offset, $offset);
            if ($grapheme === false) {
                break;
            }
            $result[] = $grapheme;
        }

        return $result;
    }
}
