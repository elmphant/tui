<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Search\Matcher;
use Elmphant\Tui\Search\SubstringMatcher;

final class CountingMatcher implements Matcher
{
    public int $calls = 0;

    /**
     * @param list<string> $haystack
     *
     * @return list<int>
     */
    public function matching(string $query, array $haystack): array
    {
        ++$this->calls;

        return (new SubstringMatcher())->matching($query, $haystack);
    }
}
