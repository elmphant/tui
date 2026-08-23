<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Tests\Support\PerformanceBudget;
use Elmphant\Tui\Widget\Prose\Separator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SeparatorTest extends TestCase
{
    use PerformanceBudget;

    #[Test]
    public function renderPerformanceBudget(): void
    {
        self::skipUnderXdebug();

        $frame = new Frame(80, 24);
        $rect = new Rect(0, 0, 40, 1);
        $context = new RenderContext(Theme::dark());
        $separator = Separator::new();

        self::assertWithinBudget(
            30.0,
            self::medianMicroseconds(static fn (): mixed => $separator->render($frame, $rect, $context)),
            'Separator::render',
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
