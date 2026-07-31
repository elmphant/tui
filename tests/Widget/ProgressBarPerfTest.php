<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Color;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Style;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Widget\ProgressBar;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProgressBarPerfTest extends TestCase
{
    #[Test]
    public function renderPerformanceBudget(): void
    {
        if (extension_loaded('xdebug')) {
            self::markTestSkipped('Performance budgets are invalid under Xdebug instrumentation');
        }

        $frame = new Frame(80, 24);
        $rect = new Rect(0, 0, 40, 1);
        $context = new RenderContext(Theme::dark());
        $bar = ProgressBar::new()
            ->withProgress(0.5)
            ->withLabel('CPU')
            ->withFillStyle(new Style(foreground: Color::hex('FF6B6B')))
            ->withEmptyStyle(new Style(foreground: Color::ansi(8)));

        $iterations = 20_000;
        $start = hrtime(true);
        for ($i = 0; $i < $iterations; ++$i) {
            $bar->render($frame, $rect, $context);
        }
        $elapsed = (hrtime(true) - $start) / 1e9;
        $perCallUs = ($elapsed / $iterations) * 1e6;

        self::assertLessThan(
            30.0,
            $perCallUs,
            sprintf('ProgressBar::render must be < 30µs; got %.2fµs', $perCallUs),
        );
    }
}
