<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Filter;

use Elmphant\Tui\Search\Matcher;
use Elmphant\Tui\Widget\Listing\ListItem;

/**
 * @internal what a query kept of a list, held with what it was worked out from
 */
final readonly class FilterMatches
{
    /**
     * @param list<ListItem> $items
     * @param list<int>      $indexes
     */
    private function __construct(
        private array $items,
        private ?Matcher $matcher,
        private string $query,
        public array $indexes,
    ) {
    }

    /**
     * @param list<ListItem> $items
     */
    public static function of(array $items, ?Matcher $matcher, string $query): self
    {
        return new self($items, $matcher, $query, FilterBar::matching(
            $matcher,
            $query,
            array_map(static fn (ListItem $item): string => $item->filterValue(), $items),
        ));
    }

    /**
     * @param list<ListItem> $items
     */
    public function isFor(array $items, ?Matcher $matcher, string $query): bool
    {
        return $query === $this->query && $matcher === $this->matcher && $items === $this->items;
    }
}
