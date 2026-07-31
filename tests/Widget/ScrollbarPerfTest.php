<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Widget\Scrollbar;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ScrollbarPerfTest extends TestCase
{
    #[Test]
    public function renderPerformanceBudget(): void
    {
        if (extension_loaded('xdebug')) {
            self::markTestSkipped('Performance budgets are invalid under Xdebug instrumentation');
        }

        $frame = new Frame(80, 50);

        $iterations = 20_000;
        $start = hrtime(true);
        for ($i = 0; $i < $iterations; ++$i) {
            Scrollbar::render($frame, 79, 0, 40, 10, 200, 40, 7, 8, 0);
        }
        $elapsed = (hrtime(true) - $start) / 1e9;
        $perCallUs = ($elapsed / $iterations) * 1e6;

        self::assertLessThan(
            30.0,
            $perCallUs,
            sprintf('Scrollbar::render must be < 30µs; got %.2fµs', $perCallUs),
        );
    }
}
