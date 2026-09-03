<?php

declare(strict_types=1);

namespace Elmphant\Tui\Rendering;

final class CharWidth
{
    private const ELLIPSIS = '…';

    private const NON_PLAIN = '/[^\x20-\x7e]/';

    private const PLAIN_PAIR = '/[\x20-\x7e][\x20-\x7e]/';

    private const EMOJI_VARIATION_SELECTOR = "\u{FE0F}";

    private const ZERO_WIDTH = [
        [0x0300, 0x036F], [0x0483, 0x0489], [0x0591, 0x05BD], [0x05BF, 0x05BF],
        [0x05C1, 0x05C2], [0x05C4, 0x05C5], [0x05C7, 0x05C7], [0x0610, 0x061A],
        [0x064B, 0x065F], [0x0670, 0x0670], [0x06D6, 0x06DC], [0x06DF, 0x06E4],
        [0x06E7, 0x06E8], [0x06EA, 0x06ED], [0x0711, 0x0711], [0x0730, 0x074A],
        [0x07A6, 0x07B0], [0x0816, 0x0819], [0x081B, 0x0823], [0x0825, 0x0827],
        [0x0829, 0x082D], [0x0859, 0x085B], [0x0898, 0x089F], [0x08CA, 0x08E1],
        [0x08E3, 0x0902], [0x093A, 0x093A], [0x093C, 0x093C], [0x0941, 0x0948],
        [0x094D, 0x094D], [0x0951, 0x0957], [0x0962, 0x0963], [0x0981, 0x0981],
        [0x09BC, 0x09BC], [0x09C1, 0x09C4], [0x09CD, 0x09CD], [0x09E2, 0x09E3],
        [0x0A01, 0x0A02], [0x0A3C, 0x0A3C], [0x0A41, 0x0A42], [0x0A47, 0x0A48],
        [0x0A4B, 0x0A4D], [0x0A51, 0x0A51], [0x0A70, 0x0A71], [0x0A75, 0x0A75],
        [0x1AB0, 0x1ACE], [0x1DC0, 0x1DFF], [0x200B, 0x200F], [0x2028, 0x202E],
        [0x2060, 0x2064], [0x2066, 0x206F], [0x20D0, 0x20F0], [0xFE00, 0xFE0F],
        [0xFE20, 0xFE2F], [0xFEFF, 0xFEFF], [0xFFF9, 0xFFFB],
        [0xE0001, 0xE0001], [0xE0020, 0xE007F], [0xE0100, 0xE01EF],
    ];

    /**
     * Emoji whose default presentation is text: the terminal draws one cell
     * unless VS16 (U+FE0F) follows, so they must not sit in WIDE or every
     * column after one drifts. Derived from East_Asian_Width — these are the
     * 31 codepoints below U+3000 that WIDE claimed but Unicode calls narrow.
     */
    private const EMOJI_PRESENTATION_BASE = [
        [0x23ED, 0x23EF], [0x23F1, 0x23F2], [0x23F8, 0x23FA], [0x2702, 0x2702],
        [0x2708, 0x2709], [0x270C, 0x270D], [0x270F, 0x270F], [0x2712, 0x2712],
        [0x2714, 0x2714], [0x2716, 0x2716], [0x271D, 0x271D], [0x2721, 0x2721],
        [0x2733, 0x2734], [0x2744, 0x2744], [0x2747, 0x2747], [0x2763, 0x2764],
        [0x27A1, 0x27A1], [0x2934, 0x2935], [0x2B05, 0x2B07],
    ];

    private const WIDE = [
        [0x1100, 0x115F], [0x231A, 0x231B], [0x2329, 0x232A], [0x23E9, 0x23EC],
        [0x23F0, 0x23F0], [0x23F3, 0x23F3], [0x25FD, 0x25FE], [0x2614, 0x2615],
        [0x2648, 0x2653], [0x267F, 0x267F], [0x2693, 0x2693], [0x26A1, 0x26A1],
        [0x26AA, 0x26AB], [0x26BD, 0x26BE], [0x26C4, 0x26C5], [0x26CE, 0x26CE],
        [0x26D4, 0x26D4], [0x26EA, 0x26EA], [0x26F2, 0x26F3], [0x26F5, 0x26F5],
        [0x26FA, 0x26FA], [0x26FD, 0x26FD], [0x2705, 0x2705], [0x270A, 0x270B],
        [0x2728, 0x2728], [0x274C, 0x274C], [0x274E, 0x274E],
        [0x2753, 0x2755], [0x2757, 0x2757], [0x2795, 0x2797],
        [0x27B0, 0x27B0], [0x27BF, 0x27BF], [0x2B1B, 0x2B1C], [0x2B50, 0x2B50],
        [0x2B55, 0x2B55], [0x2E80, 0x303E], [0x3041, 0x33BF], [0x3400, 0x4DBF],
        [0x4E00, 0xA4CF], [0xA960, 0xA97C], [0xAC00, 0xD7A3], [0xF900, 0xFAFF],
        [0xFE10, 0xFE19], [0xFE30, 0xFE6B], [0xFF01, 0xFF60], [0xFFE0, 0xFFE6],
        [0x1F004, 0x1F004], [0x1F0CF, 0x1F0CF], [0x1F18E, 0x1F18E],
        [0x1F191, 0x1F19A], [0x1F1E0, 0x1F1FF], [0x1F200, 0x1F202],
        [0x1F210, 0x1F23B], [0x1F240, 0x1F248], [0x1F250, 0x1F251],
        [0x1F260, 0x1F265], [0x1F300, 0x1F64F], [0x1F680, 0x1F6FF],
        [0x1F900, 0x1F9FF], [0x1FA00, 0x1FA6F], [0x1FA70, 0x1FAFF],
        [0x20000, 0x2FFFD], [0x30000, 0x3FFFD],
    ];

    /**
     * A CJK-heavy 200x50 screenful holds 90 distinct clusters, and a full memo
     * costs 285 KB. Overflow drops all of it rather than the oldest entry: one
     * screenful of re-measuring, at a boundary real text does not reach.
     */
    private const GRAPHEME_BUDGET = 4_096;

    /**
     * @var array<string, int>
     */
    private static array $graphemeWidths = [];

    public static function of(string $char): int
    {
        if ($char === '' || $char === "\0") {
            return 0;
        }

        $firstByte = ord($char[0]);
        if ($firstByte < 0x20) {
            return 0;
        }
        if ($firstByte < 0x7F) {
            return 1;
        }
        if ($firstByte <= 0x9F) {
            return 0;
        }

        if (!mb_check_encoding($char, 'UTF-8')) {
            return 1;
        }

        $ord = mb_ord($char);
        if ($ord >= 0x80 && $ord <= 0x9F) {
            return 0;
        }

        if (self::inRanges($ord, self::ZERO_WIDTH)) {
            return 0;
        }
        if (self::inRanges($ord, self::WIDE)) {
            return 2;
        }

        return 1;
    }

    /**
     * @internal the memo is static and its budget empties at a point no test
     *           controls, so a test asserting a width clears it first or it may
     *           read what an earlier one left behind
     */
    public static function forgetMeasurements(): void
    {
        self::$graphemeWidths = [];
    }

    public static function graphemeWidth(string $grapheme): int
    {
        // The memo comes first so a repeated cluster costs one lookup, whether it
        // is ASCII or not; measuring is what the rest of this method is for.
        if (isset(self::$graphemeWidths[$grapheme])) {
            return self::$graphemeWidths[$grapheme];
        }

        if ($grapheme === '') {
            return 0;
        }

        $width = strlen($grapheme) === 1 && ord($grapheme) < 0x80
            ? self::of($grapheme)
            : self::measureGrapheme($grapheme);

        if (count(self::$graphemeWidths) >= self::GRAPHEME_BUDGET) {
            self::$graphemeWidths = [];
        }

        return self::$graphemeWidths[$grapheme] = $width;
    }

    public static function stringWidth(string $str): int
    {
        $length = strlen($str);

        // One scan settles both cases: no match means every byte is printable
        // ASCII and one cell wide, and a match gives the first boundary free.
        if (preg_match(self::NON_PLAIN, $str, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return $length;
        }

        $width = 0;
        $offset = 0;
        $plainEnd = $match[0][1];

        while (true) {
            // Printable ASCII is one cell each, so the run needs no measuring —
            // except its final byte, which a following combining mark binds to.
            if ($plainEnd > $offset) {
                $width += $plainEnd - $offset - 1;
                $offset = $plainEnd - 1;
            }

            $span = self::spanToNextPlainRun($str, $offset, $length);
            $width += self::graphemeRunWidth(substr($str, $offset, $span));
            $offset += $span;

            if ($offset >= $length) {
                return $width;
            }

            $plainEnd = self::nextNonPlain($str, $offset, $length);
            if ($plainEnd >= $length) {
                return $width + ($length - $offset);
            }
        }
    }

    public static function stripAnsi(string $str): string
    {
        if (!str_contains($str, "\x1b")) {
            return $str;
        }

        $text = '';
        foreach (AnsiTokenizer::tokenize($str) as $token) {
            if ($token->type === AnsiTokenType::Text) {
                $text .= $token->content;
            }
        }

        return $text;
    }

    public static function ansiAwareWidth(string $str): int
    {
        return self::stringWidth(self::stripAnsi($str));
    }

    /**
     * Break text into lines no wider than $maxWidth, on word boundaries.
     *
     * A word wider than the column is split across lines, because leaving it
     * whole would overflow whatever it is drawn into.
     *
     * @return list<string> always at least one line
     */
    public static function wrapToWidth(string $text, int $maxWidth): array
    {
        if ($maxWidth <= 0 || $text === '') {
            return [''];
        }

        $lines = [];
        $current = '';
        $currentWidth = 0;

        foreach (explode(' ', $text) as $word) {
            // Measured once per word and carried, not re-measured over the whole
            // line each time: a space ends a grapheme cluster, so the widths add.
            $wordWidth = self::stringWidth($word);
            $candidateWidth = $current === '' ? $wordWidth : $currentWidth + 1 + $wordWidth;

            if ($candidateWidth <= $maxWidth) {
                $current = $current === '' ? $word : $current . ' ' . $word;
                $currentWidth = $candidateWidth;
                continue;
            }

            if ($current !== '') {
                $lines[] = $current;
                $current = '';
                $currentWidth = 0;
            }

            while ($wordWidth > $maxWidth) {
                $head = self::truncateToWidth($word, $maxWidth);

                // A grapheme wider than the whole column fits nowhere. Drawing it
                // anyway paints over the neighbour, so it is marked as cut and the
                // loop advances past it on the marker's width.
                if ($head === '') {
                    $word = substr($word, strlen(Grapheme::first($word)));
                    $lines[] = self::ELLIPSIS;
                    $wordWidth = self::stringWidth($word);

                    continue;
                }

                $lines[] = $head;
                $word = substr($word, strlen($head));
                $wordWidth = self::stringWidth($word);
            }

            $current = $word;
            $currentWidth = $wordWidth;
        }

        if ($current !== '' || $lines === []) {
            $lines[] = $current;
        }

        return $lines;
    }

    /**
     * Truncate to $maxWidth, marking the cut with an ellipsis.
     *
     * Text that simply stops at the edge reads as a rendering fault; the marker
     * says "there is more" — use this wherever content may exceed its column.
     */
    public static function ellipsize(string $text, int $maxWidth): string
    {
        if ($maxWidth <= 0) {
            return '';
        }

        if (self::stringWidth($text) <= $maxWidth) {
            return $text;
        }

        // The marker occupies one cell; a wide grapheme may not fill the rest.
        return self::truncateToWidth($text, $maxWidth - 1) . self::ELLIPSIS;
    }

    /**
     * The text from $columns display columns on. A character straddling the cut
     * goes with the part being dropped: half of one is not a character.
     */
    public static function dropColumns(string $text, int $columns): string
    {
        if ($columns <= 0) {
            return $text;
        }

        $skipped = 0;
        $kept = '';
        foreach (Grapheme::split($text) as $grapheme) {
            if ($skipped >= $columns) {
                $kept .= $grapheme;
                continue;
            }
            $skipped += self::graphemeWidth($grapheme);
        }

        return $kept;
    }

    public static function truncateToWidth(string $text, int $maxWidth): string
    {
        if ($maxWidth <= 0) {
            return '';
        }

        $length = strlen($text);

        if (preg_match(self::NON_PLAIN, $text, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return $length <= $maxWidth ? $text : substr($text, 0, $maxWidth);
        }

        $width = 0;
        $offset = 0;
        $plainEnd = $match[0][1];
        $result = '';

        while ($offset < $length && $width < $maxWidth) {
            $endsText = $plainEnd >= $length;
            $run = $plainEnd - $offset;
            $plain = $endsText ? $run : max(0, $run - 1);

            if ($plain > 0) {
                $take = min($plain, $maxWidth - $width);
                $result .= substr($text, $offset, $take);
                $offset += $take;
                $width += $take;

                if ($take < $plain || $endsText) {
                    break;
                }
            }

            $span = self::spanToNextPlainRun($text, $offset, $length);
            foreach (Grapheme::split(substr($text, $offset, $span)) as $grapheme) {
                $graphemeWidth = self::graphemeWidth($grapheme);
                if ($width + $graphemeWidth > $maxWidth) {
                    return $result;
                }
                $result .= $grapheme;
                $width += $graphemeWidth;
            }
            $offset += $span;

            if ($offset < $length) {
                $plainEnd = self::nextNonPlain($text, $offset, $length);
            }
        }

        return $result;
    }

    private static function measureGrapheme(string $grapheme): int
    {
        if (!mb_check_encoding($grapheme, 'UTF-8')) {
            if (strlen($grapheme) === 1 && ord($grapheme) >= 0x80 && ord($grapheme) <= 0x9F) {
                return 0;
            }

            return 1;
        }

        if (mb_strlen($grapheme) === 1) {
            return self::of($grapheme);
        }

        $firstCodepoint = mb_ord($grapheme);
        if (self::inRanges($firstCodepoint, self::WIDE)) {
            return 2;
        }

        if (str_contains($grapheme, self::EMOJI_VARIATION_SELECTOR)
            && self::inRanges($firstCodepoint, self::EMOJI_PRESENTATION_BASE)
        ) {
            return 2;
        }

        return 1;
    }

    /**
     * Bytes from $offset that must be judged as graphemes rather than counted.
     *
     * Only a boundary between two printable ASCII characters is safe to cut on:
     * a cluster can bind backwards (combining mark onto an ASCII base) and
     * forwards (Prepend onto an ASCII base), so a segment that ends on an
     * ASCII character with anything non-ASCII after it keeps absorbing.
     */
    private static function spanToNextPlainRun(string $text, int $offset, int $length): int
    {
        return preg_match(self::PLAIN_PAIR, $text, $match, PREG_OFFSET_CAPTURE, min($offset + 1, $length)) === 1
            ? $match[0][1] + 1 - $offset
            : $length - $offset;
    }

    private static function nextNonPlain(string $text, int $offset, int $length): int
    {
        return preg_match(self::NON_PLAIN, $text, $match, PREG_OFFSET_CAPTURE, $offset) === 1
            ? $match[0][1]
            : $length;
    }

    private static function graphemeRunWidth(string $run): int
    {
        $width = 0;
        foreach (Grapheme::split($run) as $grapheme) {
            $width += self::graphemeWidth($grapheme);
        }

        return $width;
    }

    /**
     * @param list<array{int, int}> $ranges sorted [start, end] pairs
     */
    private static function inRanges(int $cp, array $ranges): bool
    {
        $lo = 0;
        $hi = count($ranges) - 1;
        while ($lo <= $hi) {
            $mid = ($lo + $hi) >> 1;
            if ($cp < $ranges[$mid][0]) {
                $hi = $mid - 1;
            } elseif ($cp > $ranges[$mid][1]) {
                $lo = $mid + 1;
            } else {
                return true;
            }
        }

        return false;
    }
}
