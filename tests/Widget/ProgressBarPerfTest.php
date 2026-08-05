<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Color;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Style;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Tests\Support\PerformanceBudget;
use Elmphant\Tui\Widget\ProgressBar;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProgressBarPerfTest extends TestCase
{
    use PerformanceBudget;

    #[Test]
    public function renderPerformanceBudget(): void
    {
        self::skipUnderXdebug();

        $frame = new Frame(80, 24);
        $rect = new Rect(0, 0, 40, 1);
        $context = new RenderContext(Theme::dark());
        $bar = self::instrumentedBar();

        self::assertWithinBudget(
            30.0,
            self::medianMicroseconds(static fn (): null => $bar->render($frame, $rect, $context)),
            'ProgressBar::render',
        );
    }

    #[Test]
    public function aLargerFrameDoesNotCostMoreToDrawTheSameBar(): void
    {
        self::skipUnderXdebug();

        $rect = new Rect(0, 0, 40, 1);
        $context = new RenderContext(Theme::dark());
        $bar = self::instrumentedBar();

        $small = new Frame(80, 24);
        $large = new Frame(400, 200);

        $onSmall = self::medianMicroseconds(static fn (): null => $bar->render($small, $rect, $context));
        $onLarge = self::medianMicroseconds(static fn (): null => $bar->render($large, $rect, $context));
        $ratio = $onLarge / $onSmall;

        self::assertLessThan(
            self::MAX_RATIO,
            $ratio,
            sprintf(
                'A 40x larger frame made ProgressBar::render %.1fx slower (%.1fµs vs %.1fµs). '
                . 'The cost must track the rect, never the frame.',
                $ratio,
                $onLarge,
                $onSmall,
            ),
        );
    }

    private static function instrumentedBar(): ProgressBar
    {
        return ProgressBar::new()
            ->withProgress(0.5)
            ->withLabel('CPU')
            ->withFillStyle(new Style(foreground: Color::hex('FF6B6B')))
            ->withEmptyStyle(new Style(foreground: Color::ansi(8)));
    }
}
