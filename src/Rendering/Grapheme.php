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
     * The leading cluster, for callers that must advance past one without
     * splitting everything behind it.
     */
    public static function first(string $string): string
    {
        return self::split($string)[0] ?? '';
    }

    /**
     * @return list<string>
     */
    public static function split(string $string): array
    {
        // \X matches one extended grapheme cluster, so PCRE segments the whole
        // string in a single call — but /u makes it fail outright on malformed
        // UTF-8, which is what the walk below is for: it segments each valid run
        // and keeps every broken byte as a cluster of its own.
        if (preg_match_all('/\X/u', $string, $matches) !== false) {
            return $matches[0];
        }

        $result = [];
        $run = '';
        $length = strlen($string);

        for ($offset = 0; $offset < $length;) {
            $sequence = substr($string, $offset, self::sequenceLength(ord($string[$offset])));

            if (mb_check_encoding($sequence, 'UTF-8')) {
                $run .= $sequence;
            } else {
                $sequence = $string[$offset];
                array_push($result, ...self::split($run));
                $result[] = $sequence;
                $run = '';
            }

            $offset += strlen($sequence);
        }

        return $run === '' ? $result : [...$result, ...self::split($run)];
    }
}
