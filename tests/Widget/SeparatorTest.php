<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Widget\Separator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SeparatorTest extends TestCase
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
        $separator = Separator::new();

        $iterations = 20_000;
        $start = hrtime(true);
        for ($i = 0; $i < $iterations; ++$i) {
            $separator->render($frame, $rect, $context);
        }
        $elapsed = (hrtime(true) - $start) / 1e9;
        $perCallUs = ($elapsed / $iterations) * 1e6;

        self::assertLessThan(
            30.0,
            $perCallUs,
            sprintf('Separator::render must be < 30µs; got %.2fµs', $perCallUs),
        );
    }

    #[Test]
    public function renderFillsExactlyOneRowWithHorizontalGlyph(): void
    {
        $frame = new Frame(5, 3);
        for ($i = 0; $i < 15; ++$i) {
            $frame->putChar($i % 5, intdiv($i, 5), '.');
        }
        $separator = Separator::new();

        $separator->render($frame, new Rect(0, 1, 5, 1), new RenderContext(Theme::dark()));

        for ($x = 0; $x < 5; ++$x) {
            self::assertSame('.', $frame->characters[$x], "Row 0 cell {$x} must remain untouched");
            self::assertSame('─', $frame->characters[5 + $x], "Row 1 cell {$x} must be ─");
            self::assertSame('.', $frame->characters[10 + $x], "Row 2 cell {$x} must remain untouched");
        }
    }

    #[Test]
    public function renderWritesNoAttributes(): void
    {
        $frame = new Frame(5, 1);
        $separator = Separator::new();

        $separator->render($frame, new Rect(0, 0, 5, 1), new RenderContext(Theme::dark()));

        self::assertSame(0, $frame->attributes[0], 'Separator must render without text attributes (no bold etc.)');
        self::assertSame(0, $frame->attributes[4]);
    }
}
