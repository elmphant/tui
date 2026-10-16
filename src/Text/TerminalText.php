<?php

declare(strict_types=1);

namespace Elmphant\Tui\Text;

/**
 * Converts untrusted application text into inert terminal text.
 *
 * Normal widget text never passes ECMA-48 sequences or control bytes through to
 * terminal output. Applications that intentionally consume SGR styling must use
 * a parser such as Paragraph::fromAnsi(), which converts it to typed Style data.
 */
final class TerminalText
{
    /**
     * Removes every C0 line-control as required inside OSC payloads.
     */
    public static function singleLine(string $text): string
    {
        return str_replace(["\t", "\n", "\r"], '', self::plain($text));
    }

    public static function plain(string $text): string
    {
        if (preg_match('/^[\x20-\x7E]*\z/', $text) === 1) {
            return $text;
        }

        if (str_contains($text, "\x1b")) {
            $plain = '';
            foreach (AnsiTokenizer::tokenize($text) as $token) {
                if ($token->type === AnsiTokenType::Text) {
                    $plain .= $token->content;
                }
            }
            $text = $plain;
        }

        $result = '';
        $length = strlen($text);
        for ($position = 0; $position < $length;) {
            $byte = ord($text[$position]);

            if ($byte < 0x80) {
                // Keep TAB/LF/CR for Paragraph and higher-level layout parsers.
                if ($byte === 0x09 || $byte === 0x0A || $byte === 0x0D || $byte >= 0x20) {
                    if ($byte !== 0x7F) {
                        $result .= $text[$position];
                    }
                }
                ++$position;
                continue;
            }

            // A standalone 8-bit C1 byte is unsafe even though it is invalid UTF-8.
            if ($byte <= 0x9F) {
                ++$position;
                continue;
            }

            $sequenceLength = Grapheme::sequenceLength($byte);
            $sequence = substr($text, $position, $sequenceLength);

            if (mb_check_encoding($sequence, 'UTF-8')) {
                // U+0080..U+009F have exactly C2 80..C2 9F.
                if ($byte !== 0xC2 || ord($sequence[1]) > 0x9F) {
                    $result .= $sequence;
                }
                $position += $sequenceLength;
                continue;
            }

            // Preserve malformed non-control bytes verbatim.
            $result .= $text[$position];
            ++$position;
        }

        return $result;
    }
}
