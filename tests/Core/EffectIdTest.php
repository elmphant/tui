<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Core;

use Elmphant\Tui\Core\EffectId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EffectIdTest extends TestCase
{
    #[Test]
    public function anEffectIsWithinItsScopeAndEveryScopeAroundIt(): void
    {
        $feed = EffectId::named('feed')->within('inner')->within('outer');

        self::assertSame(
            [true, true, false, false, false],
            [$feed->isWithin('outer/inner'), $feed->isWithin('outer'), $feed->isWithin('inner'), $feed->isWithin('out'), $feed->isWithin('outer/inner/feed')],
        );
    }

    #[Test]
    public function anEffectWithoutAScopeIsWithinNone(): void
    {
        self::assertFalse(EffectId::named('feed')->isWithin(''));
    }
}
