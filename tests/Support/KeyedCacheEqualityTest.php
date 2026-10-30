<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Support;

use Elmphant\Tui\Support\KeyedCache;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

final class KeyedCacheEqualityTest extends TestCase
{
    /**
     * Measuring a widget must not change its equality with an otherwise identical unmeasured widget.
     */
    #[Test]
    public function whatItHoldsDoesNotChangeWhatItIsWorth(): void
    {
        $measured = new KeyedCache();
        $measured->at('80', static fn (): stdClass => new stdClass());

        self::assertEquals(new KeyedCache(), $measured);
    }
}
