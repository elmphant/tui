<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget;

use InvalidArgumentException;

/**
 * @internal the one check that what a caller handed in really is items
 */
final class ListItems
{
    /**
     * @param array<mixed> $items
     */
    public static function assertAll(array $items, string $owner): void
    {
        foreach ($items as $index => $item) {
            if (!$item instanceof ListItem) {
                throw new InvalidArgumentException(sprintf('%s takes ListItem instances; item %s is %s. Wrap a plain string in a SimpleListItem.', $owner, $index, get_debug_type($item)));
            }
        }
    }
}
