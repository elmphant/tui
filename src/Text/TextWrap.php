<?php

declare(strict_types=1);

namespace Elmphant\Tui\Text;

final class TextWrap
{
    private const ELLIPSIS = '…';

    /**
     * Break text into lines no wider than $maxWidth, on word boundaries.
     *
     * A word wider than the column is split across lines, because leaving it
     * whole would overflow whatever it is drawn into.
     *
     * @return list<string> always at least one line
     */
    public static function wrap(string $text, int $maxWidth): array
    {
        if ($maxWidth <= 0 || $text === '') {
            return [''];
        }

        $lines = [];
        $current = '';
        $currentWidth = 0;

        foreach (explode(' ', $text) as $word) {
            // A space ends a grapheme cluster, so word widths add without re-measuring the line.
            $wordWidth = CharWidth::stringWidth($word);
            $candidateWidth = $current === '' ? $wordWidth : $currentWidth + 1 + $wordWidth;

            if ($candidateWidth <= $maxWidth) {
                $current = $current === '' ? $word : $current . ' ' . $word;
                $currentWidth = $candidateWidth;
                continue;
            }

            if ($current !== '') {
                $lines[] = $current;
                $current = '';
            }

            if ($wordWidth > $maxWidth) {
                $head = '';
                $headWidth = 0;

                foreach (Grapheme::split($word) as $grapheme) {
                    $width = CharWidth::graphemeWidth($grapheme);

                    // A grapheme wider than the whole column fits nowhere. Drawing it
                    // anyway paints over the neighbour, so it is marked as cut.
                    if ($width > $maxWidth) {
                        if ($head !== '') {
                            $lines[] = $head;
                        }
                        $lines[] = self::ELLIPSIS;
                        $head = '';
                        $headWidth = 0;

                        continue;
                    }

                    if ($headWidth + $width > $maxWidth) {
                        $lines[] = $head;
                        $head = '';
                        $headWidth = 0;
                    }

                    $head .= $grapheme;
                    $headWidth += $width;
                }

                $word = $head;
                $wordWidth = $headWidth;
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

        if (CharWidth::stringWidth($text) <= $maxWidth) {
            return $text;
        }

        // The marker occupies one cell; a wide grapheme may not fill the rest.
        return CharWidth::truncateToWidth($text, $maxWidth - 1) . self::ELLIPSIS;
    }
}
