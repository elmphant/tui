<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Layout;

use Elmphant\Tui\Layout\KeyedCache;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

final class KeyedCacheEqualityTest extends TestCase
{
    /**
     * What a cache holds must not change what its owner is worth: a widget that
     * has been measured compares equal to the same widget that has not.
     */
    #[Test]
    public function whatItHoldsDoesNotChangeWhatItIsWorth(): void
    {
        $measured = new KeyedCache();
        $measured->at('80', static fn (): stdClass => new stdClass());

        self::assertEquals(new KeyedCache(), $measured);
    }
}
