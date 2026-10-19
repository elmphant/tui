<?php

declare(strict_types=1);

namespace Elmphant\Tui\Search;

interface Matcher
{
    /**
     * Indexes of the haystack entries matching the query, best match first.
     * The filters keep an answer by its query and haystack, so it must depend on nothing else.
     *
     * @param list<string> $haystack
     *
     * @return list<int>
     */
    public function matching(string $query, array $haystack): array;
}
