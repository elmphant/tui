<?php

declare(strict_types=1);

namespace Elmphant\Tui\Text;

final class TextWrap
{
    private const ELLIPSIS = '…';

    /**
     * Wrap at word boundaries; split oversized words so they cannot overflow the column.
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

                    // Mark graphemes wider than the column as cut so they cannot draw over a neighbour.
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
     * Use an ellipsis to distinguish truncated text from a rendering error.
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
