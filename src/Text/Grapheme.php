<?php

declare(strict_types=1);

namespace Elmphant\Tui\Text;

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
     * Bytes in the malformed sequence at $offset: the longest start of a
     * character that never completes, as Unicode counts one U+FFFD.
     */
    public static function malformedLength(string $string, int $offset): int
    {
        $lead = ord($string[$offset]);
        if ($lead < 0xC2 || $lead > 0xF4) {
            return 1;
        }

        [$low, $high] = match ($lead) {
            0xE0 => [0xA0, 0xBF],
            0xED => [0x80, 0x9F],
            0xF0 => [0x90, 0xBF],
            0xF4 => [0x80, 0x8F],
            default => [0x80, 0xBF],
        };

        $expected = self::sequenceLength($lead);
        for ($length = 1; $length < $expected; ++$length) {
            $byte = ord($string[$offset + $length] ?? "\0");
            if ($byte < $low || $byte > $high) {
                return $length;
            }
            [$low, $high] = [0x80, 0xBF];
        }

        return $expected;
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
        // and keeps every malformed sequence as a cluster of its own.
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
                $sequence = substr($string, $offset, self::malformedLength($string, $offset));
                array_push($result, ...self::split($run));
                $result[] = $sequence;
                $run = '';
            }

            $offset += strlen($sequence);
        }

        return $run === '' ? $result : [...$result, ...self::split($run)];
    }
}
