<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Rendering;

use Elmphant\Tui\Rendering\Context\Flashes;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FlashesTest extends TestCase
{
    #[Test]
    public function aFlashFadesFromFullToNothingOverItsSeconds(): void
    {
        $flashes = new Flashes();
        $flashes->show('jobs', 'deploy', 2.0, 10.0);

        self::assertSame(1.0, $flashes->strengthOf('jobs', 'deploy', 10.0));
        self::assertSame(0.5, $flashes->strengthOf('jobs', 'deploy', 11.0));
        self::assertSame(0.0, $flashes->strengthOf('jobs', 'deploy', 12.0));
    }

    #[Test]
    public function aFlashBelongsToOneComponentAndOneKey(): void
    {
        $flashes = new Flashes();
        $flashes->show('jobs', 'deploy', 2.0, 0.0);

        self::assertSame(0.0, $flashes->strengthOf('jobs', 'build', 0.0));
        self::assertSame(0.0, $flashes->strengthOf('logs', 'deploy', 0.0));
    }

    #[Test]
    public function flashingTheSameRowAgainStartsItOver(): void
    {
        $flashes = new Flashes();
        $flashes->show('jobs', 'deploy', 2.0, 0.0);
        $flashes->show('jobs', 'deploy', 2.0, 1.0);

        self::assertSame(1.0, $flashes->strengthOf('jobs', 'deploy', 1.0));
    }

    #[Test]
    public function aFlashExpiresAtItsDeadlineAndSaysSo(): void
    {
        $flashes = new Flashes();
        $flashes->show('jobs', 'deploy', 2.0, 0.0);
        $flashes->show('jobs', 'build', 3.0, 0.0);

        self::assertSame(2.0, $flashes->nextDeadline());
        self::assertFalse($flashes->expire(1.9));
        self::assertTrue($flashes->expire(2.0));
        self::assertSame(3.0, $flashes->nextDeadline());
        self::assertTrue($flashes->expire(3.0));
        self::assertNull($flashes->nextDeadline());
    }
}
