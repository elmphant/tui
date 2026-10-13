<?php

declare(strict_types=1);

namespace Elmphant\Tui\Rendering;

/**
 * The range tables are derived from the Unicode Character Database, used under the Unicode License v3 (see LICENSE).
 */
final class CharWidth
{
    public const TAB_STOP = 8;

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
     * Emoji whose default presentation is text: one cell, two once VS16
     * (U+FE0F) follows. Unicode 16 `\p{Emoji}` minus `\p{Emoji_Presentation}`,
     * kept as data because a PCRE older than 10.40 has neither property.
     */
    private const EMOJI_PRESENTATION_BASE = [
        [0x0023, 0x0023], [0x002A, 0x002A], [0x0030, 0x0039], [0x00A9, 0x00A9], [0x00AE, 0x00AE], [0x203C, 0x203C], [0x2049, 0x2049],
        [0x2122, 0x2122], [0x2139, 0x2139], [0x2194, 0x2199], [0x21A9, 0x21AA],
        [0x2328, 0x2328], [0x23CF, 0x23CF], [0x23ED, 0x23EF], [0x23F1, 0x23F2],
        [0x23F8, 0x23FA], [0x24C2, 0x24C2], [0x25AA, 0x25AB], [0x25B6, 0x25B6],
        [0x25C0, 0x25C0], [0x25FB, 0x25FC], [0x2600, 0x2604], [0x260E, 0x260E],
        [0x2611, 0x2611], [0x2618, 0x2618], [0x261D, 0x261D], [0x2620, 0x2620],
        [0x2622, 0x2623], [0x2626, 0x2626], [0x262A, 0x262A], [0x262E, 0x262F],
        [0x2638, 0x263A], [0x2640, 0x2640], [0x2642, 0x2642], [0x265F, 0x2660],
        [0x2663, 0x2663], [0x2665, 0x2666], [0x2668, 0x2668], [0x267B, 0x267B],
        [0x267E, 0x267E], [0x2692, 0x2692], [0x2694, 0x2697], [0x2699, 0x2699],
        [0x269B, 0x269C], [0x26A0, 0x26A0], [0x26A7, 0x26A7], [0x26B0, 0x26B1],
        [0x26C8, 0x26C8], [0x26CF, 0x26CF], [0x26D1, 0x26D1], [0x26D3, 0x26D3],
        [0x26E9, 0x26E9], [0x26F0, 0x26F1], [0x26F4, 0x26F4], [0x26F7, 0x26F9],
        [0x2702, 0x2702], [0x2708, 0x2709], [0x270C, 0x270D], [0x270F, 0x270F],
        [0x2712, 0x2712], [0x2714, 0x2714], [0x2716, 0x2716], [0x271D, 0x271D],
        [0x2721, 0x2721], [0x2733, 0x2734], [0x2744, 0x2744], [0x2747, 0x2747],
        [0x2763, 0x2764], [0x27A1, 0x27A1], [0x2934, 0x2935], [0x2B05, 0x2B07],
        [0x3030, 0x3030], [0x303D, 0x303D], [0x3297, 0x3297], [0x3299, 0x3299],
        [0x1F170, 0x1F171], [0x1F17E, 0x1F17F], [0x1F202, 0x1F202], [0x1F237, 0x1F237],
        [0x1F321, 0x1F321], [0x1F324, 0x1F32C], [0x1F336, 0x1F336], [0x1F37D, 0x1F37D],
        [0x1F396, 0x1F397], [0x1F399, 0x1F39B], [0x1F39E, 0x1F39F], [0x1F3CB, 0x1F3CE],
        [0x1F3D4, 0x1F3DF], [0x1F3F3, 0x1F3F3], [0x1F3F5, 0x1F3F5], [0x1F3F7, 0x1F3F7],
        [0x1F43F, 0x1F43F], [0x1F441, 0x1F441], [0x1F4FD, 0x1F4FD], [0x1F549, 0x1F54A],
        [0x1F56F, 0x1F570], [0x1F573, 0x1F579], [0x1F587, 0x1F587], [0x1F58A, 0x1F58D],
        [0x1F590, 0x1F590], [0x1F5A5, 0x1F5A5], [0x1F5A8, 0x1F5A8], [0x1F5B1, 0x1F5B2],
        [0x1F5BC, 0x1F5BC], [0x1F5C2, 0x1F5C4], [0x1F5D1, 0x1F5D3], [0x1F5DC, 0x1F5DE],
        [0x1F5E1, 0x1F5E1], [0x1F5E3, 0x1F5E3], [0x1F5E8, 0x1F5E8], [0x1F5EF, 0x1F5EF],
        [0x1F5F3, 0x1F5F3], [0x1F5FA, 0x1F5FA], [0x1F6CB, 0x1F6CB], [0x1F6CD, 0x1F6CF],
        [0x1F6E0, 0x1F6E5], [0x1F6E9, 0x1F6E9], [0x1F6F0, 0x1F6F0], [0x1F6F3, 0x1F6F3],
    ];

    /**
     * Narrow alone, but a pair of them is a flag, which terminals draw wide.
     */
    private const REGIONAL_INDICATOR = [[0x1F1E6, 0x1F1FF]];

    /**
     * Unicode 16 East_Asian_Width W and F.
     */
    private const WIDE = [
        [0x1100, 0x115F], [0x231A, 0x231B], [0x2329, 0x232A], [0x23E9, 0x23EC],
        [0x23F0, 0x23F0], [0x23F3, 0x23F3], [0x25FD, 0x25FE], [0x2614, 0x2615],
        [0x2630, 0x2637], [0x2648, 0x2653], [0x267F, 0x267F], [0x268A, 0x268F],
        [0x2693, 0x2693], [0x26A1, 0x26A1], [0x26AA, 0x26AB], [0x26BD, 0x26BE],
        [0x26C4, 0x26C5], [0x26CE, 0x26CE], [0x26D4, 0x26D4], [0x26EA, 0x26EA],
        [0x26F2, 0x26F3], [0x26F5, 0x26F5], [0x26FA, 0x26FA], [0x26FD, 0x26FD],
        [0x2705, 0x2705], [0x270A, 0x270B], [0x2728, 0x2728], [0x274C, 0x274C],
        [0x274E, 0x274E], [0x2753, 0x2755], [0x2757, 0x2757], [0x2795, 0x2797],
        [0x27B0, 0x27B0], [0x27BF, 0x27BF], [0x2B1B, 0x2B1C], [0x2B50, 0x2B50],
        [0x2B55, 0x2B55], [0x2E80, 0x2E99], [0x2E9B, 0x2EF3], [0x2F00, 0x2FD5],
        [0x2FF0, 0x303E], [0x3041, 0x3096], [0x3099, 0x30FF], [0x3105, 0x312F],
        [0x3131, 0x318E], [0x3190, 0x31E5], [0x31EF, 0x321E], [0x3220, 0x3247],
        [0x3250, 0xA48C], [0xA490, 0xA4C6], [0xA960, 0xA97C], [0xAC00, 0xD7A3],
        [0xF900, 0xFAFF], [0xFE10, 0xFE19], [0xFE30, 0xFE52], [0xFE54, 0xFE66],
        [0xFE68, 0xFE6B], [0xFF01, 0xFF60], [0xFFE0, 0xFFE6], [0x16FE0, 0x16FE4],
        [0x16FF0, 0x16FF1], [0x17000, 0x187F7], [0x18800, 0x18CD5], [0x18CFF, 0x18D08],
        [0x1AFF0, 0x1AFF3], [0x1AFF5, 0x1AFFB], [0x1AFFD, 0x1AFFE], [0x1B000, 0x1B122],
        [0x1B132, 0x1B132], [0x1B150, 0x1B152], [0x1B155, 0x1B155], [0x1B164, 0x1B167],
        [0x1B170, 0x1B2FB], [0x1D300, 0x1D356], [0x1D360, 0x1D376], [0x1F004, 0x1F004],
        [0x1F0CF, 0x1F0CF], [0x1F18E, 0x1F18E], [0x1F191, 0x1F19A], [0x1F200, 0x1F202],
        [0x1F210, 0x1F23B], [0x1F240, 0x1F248], [0x1F250, 0x1F251], [0x1F260, 0x1F265],
        [0x1F300, 0x1F320], [0x1F32D, 0x1F335], [0x1F337, 0x1F37C], [0x1F37E, 0x1F393],
        [0x1F3A0, 0x1F3CA], [0x1F3CF, 0x1F3D3], [0x1F3E0, 0x1F3F0], [0x1F3F4, 0x1F3F4],
        [0x1F3F8, 0x1F43E], [0x1F440, 0x1F440], [0x1F442, 0x1F4FC], [0x1F4FF, 0x1F53D],
        [0x1F54B, 0x1F54E], [0x1F550, 0x1F567], [0x1F57A, 0x1F57A], [0x1F595, 0x1F596],
        [0x1F5A4, 0x1F5A4], [0x1F5FB, 0x1F64F], [0x1F680, 0x1F6C5], [0x1F6CC, 0x1F6CC],
        [0x1F6D0, 0x1F6D2], [0x1F6D5, 0x1F6D7], [0x1F6DC, 0x1F6DF], [0x1F6EB, 0x1F6EC],
        [0x1F6F4, 0x1F6FC], [0x1F7E0, 0x1F7EB], [0x1F7F0, 0x1F7F0], [0x1F90C, 0x1F93A],
        [0x1F93C, 0x1F945], [0x1F947, 0x1F9FF], [0x1FA70, 0x1FA7C], [0x1FA80, 0x1FA89],
        [0x1FA8F, 0x1FAC6], [0x1FACE, 0x1FADC], [0x1FADF, 0x1FAE9], [0x1FAF0, 0x1FAF8],
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

    public static function withTabsExpanded(string $text, int $column): string
    {
        if (!str_contains($text, "\t")) {
            return $text;
        }

        $expanded = '';
        foreach (explode("\t", $text) as $index => $piece) {
            if ($index > 0) {
                $spaces = self::TAB_STOP - ($column % self::TAB_STOP);
                $expanded .= str_repeat(' ', $spaces);
                $column += $spaces;
            }
            $expanded .= $piece;
            $column += self::stringWidth($piece);
        }

        return $expanded;
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
        if (self::inRanges($firstCodepoint, self::WIDE) || self::inRanges($firstCodepoint, self::REGIONAL_INDICATOR)) {
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
