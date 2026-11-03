<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Filter;

use Closure;
use Elmphant\Tui\Search\Matcher;

/**
 * @internal what a query kept of a list, held with what it was worked out from
 */
final readonly class FilterMatches
{
    /**
     * @param list<mixed>  $source
     * @param list<string> $haystack
     * @param list<int>    $indexes
     */
    private function __construct(
        private array $source,
        private array $haystack,
        private ?Matcher $matcher,
        private string $query,
        public array $indexes,
    ) {
    }

    /**
     * Keep what still holds: the same matches for the same question, the same search text for the same source.
     *
     * @template T
     *
     * @param list<T>                        $source
     * @param Closure(list<T>): list<string> $haystackOf
     */
    public static function kept(?self $before, array $source, Closure $haystackOf, ?Matcher $matcher, string $query): self
    {
        if ($before === null || $before->source !== $source) {
            return self::of($source, $haystackOf($source), $matcher, $query);
        }

        return $before->matcher === $matcher && $before->query === $query
            ? $before
            : self::of($source, $before->haystack, $matcher, $query);
    }

    /**
     * @param list<mixed>  $source
     * @param list<string> $haystack
     */
    private static function of(array $source, array $haystack, ?Matcher $matcher, string $query): self
    {
        return new self($source, $haystack, $matcher, $query, FilterBar::matching($matcher, $query, $haystack));
    }
}
