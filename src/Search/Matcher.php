<?php

declare(strict_types=1);

namespace Elmphant\Tui\Search;

interface Matcher
{
    /**
     * Return matching indices in best-first order; results must depend only on query and haystack because filters cache them.
     *
     * @param list<string> $haystack
     *
     * @return list<int>
     */
    public function matching(string $query, array $haystack): array;
}
