<?php

declare(strict_types=1);

namespace Elmphant\Tui\Search;

interface Matcher
{
    /**
     * Indexes of the haystack entries matching the query, best match first.
     *
     * @param list<string> $haystack
     *
     * @return list<int>
     */
    public function matching(string $query, array $haystack): array;
}
