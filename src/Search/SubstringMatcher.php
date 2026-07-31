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

        $found = [];
        foreach ($haystack as $index => $entry) {
            if (self::containsEvery($entry, $words)) {
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
     * @param list<string> $words
     */
    private static function containsEvery(string $entry, array $words): bool
    {
        foreach ($words as $word) {
            if (mb_stripos($entry, $word) === false) {
                return false;
            }
        }

        return true;
    }
}
