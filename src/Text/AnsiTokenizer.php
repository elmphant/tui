<?php

declare(strict_types=1);

namespace Elmphant\Tui\Text;

use Generator;

/**
 * Splits a byte string into text runs and ECMA-48 escape sequences.
 * Unterminated sequences at end of input are yielded as their partial token.
 *
 * @internal
 */
final class AnsiTokenizer
{
    /**
     * @return Generator<int, AnsiToken>
     */
    public static function tokenize(string $input): Generator
    {
        $length = strlen($input);
        $position = 0;

        while ($position < $length) {
            $escapePosition = strpos($input, "\x1b", $position);

            if ($escapePosition === false) {
                yield new AnsiToken(AnsiTokenType::Text, substr($input, $position));

                return;
            }

            if ($escapePosition > $position) {
                yield new AnsiToken(AnsiTokenType::Text, substr($input, $position, $escapePosition - $position));
            }

            $position = $escapePosition;
            $end = self::sequenceEnd($input, $position);
            $type = match ($input[$position + 1] ?? '') {
                '[' => AnsiTokenType::Csi,
                ']' => AnsiTokenType::Osc,
                'P' => AnsiTokenType::Dcs,
                '_' => AnsiTokenType::Apc,
                '^' => AnsiTokenType::Pm,
                'X' => AnsiTokenType::Sos,
                'O' => AnsiTokenType::Ss3,
                default => AnsiTokenType::Escape,
            };
            yield new AnsiToken($type, substr($input, $position, $end - $position));
            $position = $end;
        }
    }

    public static function sequenceEnd(string $input, int $position): int
    {
        $length = strlen($input);

        return match ($input[$position + 1] ?? '') {
            '[' => self::findCsiEnd($input, $position + 2, $length),
            ']', 'P', '_', '^', 'X' => self::findStringEnd($input, $position + 2, $length),
            'O' => min($position + 3, $length),
            default => self::findEscapeEnd($input, $position + 1, $length),
        };
    }

    private static function findEscapeEnd(string $input, int $position, int $length): int
    {
        while ($position < $length && ord($input[$position]) >= 0x20 && ord($input[$position]) <= 0x2F) {
            ++$position;
        }

        return min($position + 1, $length);
    }

    private static function findCsiEnd(string $input, int $position, int $length): int
    {
        while ($position < $length) {
            $byte = ord($input[$position]);
            ++$position;
            if ($byte >= 0x40 && $byte <= 0x7E) {
                return $position;
            }
        }

        return $length;
    }

    private static function findStringEnd(string $input, int $position, int $length): int
    {
        while ($position < $length) {
            $byte = ord($input[$position]);
            if ($byte === 0x07) {
                return $position + 1;
            }
            if ($byte === 0x1B && ($input[$position + 1] ?? '') === '\\') {
                return $position + 2;
            }
            ++$position;
        }

        return $length;
    }
}
