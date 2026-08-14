<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget;

use InvalidArgumentException;

/**
 * @internal
 */
final class Options
{
    /**
     * @param list<string> $options
     */
    public static function assertDistinct(array $options, string $owner): void
    {
        $seen = [];

        foreach ($options as $index => $option) {
            if (isset($seen[$option])) {
                throw new InvalidArgumentException(sprintf('%s options must read differently; "%s" is at %d and %d.', $owner, $option, $seen[$option], $index));
            }

            $seen[$option] = $index;
        }
    }
}
