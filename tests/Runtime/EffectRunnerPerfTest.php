<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Runtime\EffectRunner;
use Elmphant\Tui\Testing\TestClock;
use Elmphant\Tui\Tests\Support\PerformanceBudget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SplQueue;

final class EffectRunnerPerfTest extends TestCase
{
    use PerformanceBudget;

    #[Test]
    public function firingEightTimesAsManyDueTimersTakesAboutEightTimesAsLong(): void
    {
        self::skipUnderXdebug();

        self::assertRatioBelow(20.0, static function (): array {
            $few = self::microsecondsToFire(1_000);
            $many = self::microsecondsToFire(8_000);
            $ratio = $many / $few;

            return [$ratio, sprintf('8,000 due timers took %.0fx as long as 1,000 (%.0fµs vs %.0fµs)', $ratio, $many, $few)];
        });
    }

    private static function microsecondsToFire(int $timers): float
    {
        return self::medianMicrosecondsAfterSetup(
            static function () use ($timers, &$runner, &$clock): void {
                $clock = new TestClock();
                $runner = new EffectRunner(new SplQueue(), $clock, static function (): void {
                }, static function (): void {
                });
                for ($timer = 0; $timer < $timers; ++$timer) {
                    $runner->run(Cmd::after(0.0, static fn () => null), null);
                }
            },
            static function () use (&$runner, &$clock): void {
                $runner->fire($clock->now());
            },
            5,
        );
    }
}
