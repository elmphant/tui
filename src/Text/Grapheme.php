<?php

declare(strict_types=1);

namespace Elmphant\Tui\Text;

/**
 * @internal
 */
final class Grapheme
{
    /**
     * Return UTF-8 sequence length from the lead byte, or 1 for ASCII and invalid lead bytes so scanning can advance.
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
     * Consume the malformed prefix that Unicode replaces with one U+FFFD.
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

    public static function first(string $string): string
    {
        return self::split($string)[0] ?? '';
    }

    /**
     * @return list<string>
     */
    public static function split(string $string): array
    {
        // Use one PCRE call for valid UTF-8; otherwise segment valid runs and keep malformed sequences as separate clusters.
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
