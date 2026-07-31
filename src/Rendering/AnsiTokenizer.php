<?php

declare(strict_types=1);

namespace Elmphant\Tui\Rendering;

use Generator;

/**
 * Splits a byte string into text runs and ECMA-48 escape sequences.
 * Unterminated sequences at end of input are yielded as their partial token.
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

            if ($position + 1 >= $length) {
                yield new AnsiToken(AnsiTokenType::Escape, "\x1b");

                return;
            }

            $introducer = $input[$position + 1];

            if ($introducer === '[') {
                $end = self::findCsiEnd($input, $position + 2, $length);
                yield new AnsiToken(AnsiTokenType::Csi, substr($input, $position, $end - $position));
                $position = $end;
                continue;
            }

            $stringType = match ($introducer) {
                ']' => AnsiTokenType::Osc,
                'P' => AnsiTokenType::Dcs,
                '_' => AnsiTokenType::Apc,
                '^' => AnsiTokenType::Pm,
                'X' => AnsiTokenType::Sos,
                default => null,
            };
            if ($stringType !== null) {
                $end = self::findStringEnd($input, $position + 2, $length);
                yield new AnsiToken($stringType, substr($input, $position, $end - $position));
                $position = $end;
                continue;
            }

            if ($introducer === 'O') {
                $end = min($position + 3, $length);
                yield new AnsiToken(AnsiTokenType::Ss3, substr($input, $position, $end - $position));
                $position = $end;
                continue;
            }

            yield new AnsiToken(AnsiTokenType::Escape, substr($input, $position, 2));
            $position += 2;
        }
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
            if ($byte === 0x1B && $position + 1 < $length && $input[$position + 1] === '\\') {
                return $position + 2;
            }
            ++$position;
        }

        return $length;
    }
}
