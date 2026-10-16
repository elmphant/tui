<?php

declare(strict_types=1);

namespace Elmphant\Tui\Search;

final readonly class SubstringMatcher implements Matcher
{
    public function matching(string $query, array $haystack): array
    {
        $words = self::wordsIn($query);

        if ($words === []) {
            return array_keys($haystack);
        }

        // An ASCII word is found byte by byte inside UTF-8 just as well, at a tenth
        // of the cost; only folds like the Kelvin sign to "k" are lost.
        $ascii = array_filter($words, static fn (string $word): bool => preg_match('/^[\x00-\x7F]*$/', $word) === 1);
        $other = array_diff_key($words, $ascii);

        $found = [];
        foreach ($haystack as $index => $entry) {
            if (self::containsEvery($entry, $ascii, $other)) {
                $found[] = $index;
            }
        }

        return $found;
    }

    /**
     * @return list<string>
     */
    private static function wordsIn(string $query): array
    {
        $split = preg_split('/\s+/', $query, flags: \PREG_SPLIT_NO_EMPTY);

        return $split === false ? [] : $split;
    }

    /**
     * @param array<int, string> $ascii
     * @param array<int, string> $other
     */
    private static function containsEvery(string $entry, array $ascii, array $other): bool
    {
        foreach ($ascii as $word) {
            if (stripos($entry, $word) === false) {
                return false;
            }
        }

        foreach ($other as $word) {
            if (mb_stripos($entry, $word) === false) {
                return false;
            }
        }

        return true;
    }
}
