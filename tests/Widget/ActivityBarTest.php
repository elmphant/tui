<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Context\RenderPass;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Widget\Feedback\ActivityBar;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ActivityBarTest extends TestCase
{
    #[Test]
    public function constraintIsExactOne(): void
    {
        self::assertEquals(Constraint::exact(1), ActivityBar::new()->sizing()->rows);
    }

    #[Test]
    public function rendersGradientAtStartPosition(): void
    {
        $bar = ActivityBar::new();
        $frame = new Frame(20, 1);

        $bar->render($frame, Rect::fromSize(20, 1), new RenderContext(Theme::dark()));

        self::assertSame('█', $frame->characters[0]);
        self::assertSame('▓', $frame->characters[1]);
        self::assertSame('▒', $frame->characters[2]);
        self::assertSame('░', $frame->characters[3]);
        self::assertSame('─', $frame->characters[4]);
    }

    #[Test]
    public function rendersLabel(): void
    {
        $bar = ActivityBar::new('Loading');
        $frame = new Frame(30, 1);

        $bar->render($frame, Rect::fromSize(30, 1), new RenderContext(Theme::dark()));

        self::assertSame('L', $frame->characters[0]);
        self::assertSame('o', $frame->characters[1]);
        self::assertSame('█', $frame->characters[8]);
    }

    #[Test]
    public function scannerMovesWithTime(): void
    {
        $bar = self::makeBar();
        $frame = new Frame(20, 1);

        $bar->render($frame, Rect::fromSize(20, 1), self::contextAt(0.5));

        self::assertSame('─', $frame->characters[0]);
        self::assertSame('█', $frame->characters[10], 'round(0.5 * 19) = 10');
    }

    #[Test]
    public function scannerBouncesBackAfterReachingTheEnd(): void
    {
        $bar = self::makeBar();
        $frame = new Frame(20, 1);

        // 1.5 sweeps: the highlight travelled to the end and half-way back.
        $bar->render($frame, Rect::fromSize(20, 1), self::contextAt(1.5));

        self::assertSame('─', $frame->characters[19]);
        self::assertSame('█', $frame->characters[10], 'Bounced position 0.5 → column 10');
    }

    #[Test]
    public function scannerReachesExactlyTheLastColumnAtOneSweep(): void
    {
        $bar = self::makeBar();
        $frame = new Frame(20, 1);

        $bar->render($frame, Rect::fromSize(20, 1), self::contextAt(1.0));

        self::assertSame('█', $frame->characters[19]);
        self::assertSame('▓', $frame->characters[18]);
    }

    #[Test]
    public function scannerReturnsToTheStartAfterAFullCycle(): void
    {
        $bar = self::makeBar();
        $frame = new Frame(20, 1);

        $bar->render($frame, Rect::fromSize(20, 1), self::contextAt(2.0));

        self::assertSame('█', $frame->characters[0]);
    }

    #[Test]
    public function animationCycleWrapsBeyondTwoSweeps(): void
    {
        $bar = self::makeBar();
        $frame = new Frame(20, 1);

        $bar->render($frame, Rect::fromSize(20, 1), self::contextAt(2.5));

        self::assertSame('█', $frame->characters[10], 'fmod(2.5, 2.0) = 0.5 → column 10');
    }

    #[Test]
    public function speedScalesTheAnimation(): void
    {
        $bar = self::makeBar(speed: 0.1);
        $frame = new Frame(20, 1);

        // 16 seconds at 0.1 sweeps/s = 1.6 sweeps → bounced back to 0.4.
        $bar->render($frame, Rect::fromSize(20, 1), self::contextAt(16.0));

        self::assertSame('█', $frame->characters[8], 'round(0.4 * 19) = 8');
    }

    #[Test]
    public function renderRequestsARepaintBeforeTheNextColumnStep(): void
    {
        $bar = self::makeBar(speed: 1.0, barWidth: 20);
        $frame = new Frame(20, 1);
        $pass = self::passAt(0.0);

        $bar->render($frame, Rect::fromSize(20, 1), $pass->context);

        self::assertSame(1.0 / 19.0, $pass->clock->repaintDelay(), 'One column step at speed 1.0 with 19 steps');
    }

    #[Test]
    public function repaintRequestReflectsSpeed(): void
    {
        $bar = self::makeBar(speed: 0.5, barWidth: 20);
        $frame = new Frame(20, 1);
        $pass = self::passAt(0.0);

        $bar->render($frame, Rect::fromSize(20, 1), $pass->context);

        self::assertSame(1.0 / (0.5 * 19.0), $pass->clock->repaintDelay());
    }

    #[Test]
    public function repaintRequestWithBarWidthOneUsesAFullSweep(): void
    {
        $bar = self::makeBar(speed: 1.0, barWidth: 1);
        $frame = new Frame(5, 1);
        $pass = self::passAt(0.0);

        $bar->render($frame, Rect::fromSize(5, 1), $pass->context);

        self::assertSame(1.0, $pass->clock->repaintDelay(), 'max(1, lastIndex) guards the division for single-cell bars');
    }

    #[Test]
    public function withSpeedClampsToAPositiveMinimum(): void
    {
        $bar = self::makeBar(speed: 0.0, barWidth: 20);
        $frame = new Frame(20, 1);
        $pass = self::passAt(0.0);

        $bar->render($frame, Rect::fromSize(20, 1), $pass->context);

        self::assertSame(1.0 / (0.01 * 19.0), $pass->clock->repaintDelay(), 'Zero speed clamps to 0.01 sweeps/s');
    }

    #[Test]
    public function usesThemeAccentColor(): void
    {
        $bar = ActivityBar::new();
        $theme = Theme::dark();
        $frame = new Frame(20, 1);

        $bar->render($frame, Rect::fromSize(20, 1), new RenderContext($theme));

        $accentFg = $theme->accent->foregroundValue();
        self::assertSame($accentFg, $frame->foregrounds[0]);
    }

    #[Test]
    public function handlesZeroWidth(): void
    {
        $bar = ActivityBar::new();
        $frame = new Frame(1, 1);

        $bar->render($frame, new Rect(0, 0, 0, 1), new RenderContext(Theme::dark()));

        self::assertTrue(true);
    }

    #[Test]
    public function handlesZeroHeight(): void
    {
        $bar = ActivityBar::new();
        $frame = new Frame(20, 1);

        $bar->render($frame, new Rect(0, 0, 20, 0), new RenderContext(Theme::dark()));

        self::assertTrue(true);
    }

    #[Test]
    public function defaultBarWidthIsTwenty(): void
    {
        $bar = ActivityBar::new();
        $frame = new Frame(25, 1);
        $context = new RenderContext(Theme::dark());

        $bar->render($frame, Rect::fromSize(25, 1), $context);

        self::assertSame('─', $frame->characters[19], 'Last track char at index 19 (barWidth=20)');
        self::assertSame(' ', $frame->characters[20], 'Position 20 should be empty (outside barWidth=20)');
    }

    #[Test]
    public function rendersWithMinimalAreaOfOneByOne(): void
    {
        $bar = ActivityBar::new();
        $frame = new Frame(1, 1);
        $context = new RenderContext(Theme::dark());

        $bar->render($frame, Rect::fromSize(1, 1), $context);

        self::assertSame('█', $frame->characters[0]);
    }

    #[Test]
    public function skipsRenderingWhenWidthIsZeroAndHeightIsOne(): void
    {
        $bar = ActivityBar::new();
        $frame = new Frame(5, 1);
        $context = new RenderContext(Theme::dark());

        $bar->render($frame, new Rect(0, 0, 0, 1), $context);

        self::assertSame(' ', $frame->characters[0], 'Buffer unchanged with zero width');
    }

    #[Test]
    public function skipsRenderingWhenHeightIsZeroAndWidthIsPositive(): void
    {
        $bar = ActivityBar::new();
        $frame = new Frame(20, 1);
        $context = new RenderContext(Theme::dark());

        $bar->render($frame, new Rect(0, 0, 20, 0), $context);

        self::assertSame(' ', $frame->characters[0], 'Buffer unchanged with zero height');
    }

    #[Test]
    public function bufferUnchangedWhenAreaHasZeroDimension(): void
    {
        $bar = ActivityBar::new();
        $frame = new Frame(5, 1);
        $context = new RenderContext(Theme::dark());
        $charactersBefore = $frame->characters;

        $bar->render($frame, new Rect(0, 0, 0, 0), $context);

        self::assertSame($charactersBefore, $frame->characters, 'Buffer must not change on zero-area render');
    }

    #[Test]
    public function zeroAreaRenderRequestsNoRepaint(): void
    {
        $bar = ActivityBar::new();
        $frame = new Frame(5, 1);
        $pass = RenderPass::new(Theme::dark());

        $bar->render($frame, new Rect(0, 0, 0, 0), $pass->context);

        self::assertNull($pass->clock->repaintDelay(), 'An invisible bar must not keep the render loop awake');
    }

    #[Test]
    public function noLabelRendersBarAtColumnZero(): void
    {
        $bar = ActivityBar::new();
        $frame = new Frame(20, 1);
        $context = new RenderContext(Theme::dark());

        $bar->render($frame, Rect::fromSize(20, 1), $context);

        self::assertSame('█', $frame->characters[0], 'Without label, gradient starts at column 0');
    }

    #[Test]
    public function barStartsAfterLabelOffset(): void
    {
        $bar = self::makeBar(label: 'AB', barWidth: 5);
        $frame = new Frame(15, 1);
        $context = new RenderContext(Theme::dark());

        // label "AB" has labelWidth = 2 + 1 = 3, so the bar starts at 2 + 3 = 5
        $bar->render($frame, new Rect(2, 0, 13, 1), $context);

        self::assertSame('A', $frame->characters[2], 'Label starts at area x offset');
        self::assertSame('B', $frame->characters[3]);
        self::assertSame(' ', $frame->characters[4], 'Space between label and bar');
        self::assertSame('█', $frame->characters[5], 'Bar gradient starts at area.x + labelWidth (2+3=5)');
    }

    #[Test]
    public function availableBarWidthSubtractsLabelWidth(): void
    {
        // label "Test" = 4 chars + 1 space = labelWidth 5
        // area width = 10, availableBarWidth = 10 - 5 = 5, barWidth = min(50, 5) = 5
        $bar = self::makeBar(label: 'Test', barWidth: 50);
        $frame = new Frame(10, 1);
        $context = new RenderContext(Theme::dark());

        $bar->render($frame, Rect::fromSize(10, 1), $context);

        self::assertSame('T', $frame->characters[0]);
        self::assertSame('█', $frame->characters[5], 'Gradient at label end (4+1=5)');

        $renderedBarColumns = 0;
        for ($column = 5; $column < 10; ++$column) {
            if ($frame->characters[$column] !== ' ') {
                ++$renderedBarColumns;
            }
        }
        self::assertSame(5, $renderedBarColumns, 'Bar fills exactly width-labelWidth columns');

        $wideBuffer = new Frame(20, 1);
        $bar->render($wideBuffer, Rect::fromSize(20, 1), $context);
        self::assertNotSame(' ', $wideBuffer->characters[19], 'Last column of wider bar is filled');
    }

    #[Test]
    public function rendersWithBarWidthOfExactlyOne(): void
    {
        $bar = self::makeBar(barWidth: 1);
        $frame = new Frame(5, 1);
        $context = new RenderContext(Theme::dark());

        $bar->render($frame, Rect::fromSize(5, 1), $context);

        self::assertSame('█', $frame->characters[0], 'Single-cell bar renders the center gradient');
        self::assertSame(' ', $frame->characters[1], 'No content beyond barWidth');
    }

    #[Test]
    public function centerColumnCalculationAtPositionOne(): void
    {
        $bar = self::makeBar(barWidth: 20);
        $frame = new Frame(20, 1);

        $bar->render($frame, Rect::fromSize(20, 1), self::contextAt(1.0));

        self::assertSame('█', $frame->characters[19], 'At position 1.0, center is at last column (19)');
        self::assertSame('▓', $frame->characters[18]);
        self::assertSame('▒', $frame->characters[17]);
        self::assertSame('░', $frame->characters[16]);
        self::assertSame('─', $frame->characters[15]);
    }

    #[Test]
    public function centerColumnCalculationAtMidpoint(): void
    {
        $bar = self::makeBar(barWidth: 20);
        $frame = new Frame(20, 1);

        $bar->render($frame, Rect::fromSize(20, 1), self::contextAt(0.5));

        // round(0.5 * 19) = round(9.5) = 10
        self::assertSame('█', $frame->characters[10], 'Midpoint center column');
        self::assertSame('▓', $frame->characters[9]);
        self::assertSame('▓', $frame->characters[11]);
    }

    #[Test]
    public function centerColumnWithBarWidthOne(): void
    {
        $bar = self::makeBar(barWidth: 1);
        $frame = new Frame(5, 1);

        $bar->render($frame, Rect::fromSize(5, 1), self::contextAt(0.5));

        // round(0.5 * (1 - 1)) = 0
        self::assertSame('█', $frame->characters[0]);
    }

    #[Test]
    public function roundingNotFloorOrCeil(): void
    {
        // position=0.55, barWidth=10: round(0.55 * 9) = round(4.95) = 5
        // floor(4.95) = 4 — the assertion distinguishes round from floor
        $bar = self::makeBar(barWidth: 10);
        $frame = new Frame(10, 1);

        $bar->render($frame, Rect::fromSize(10, 1), self::contextAt(0.55));

        self::assertSame('█', $frame->characters[5], 'round(4.95) = 5, not floor(4.95)=4');
    }

    #[Test]
    public function roundingNotCeil(): void
    {
        // position=0.25, barWidth=10: round(0.25 * 9) = round(2.25) = 2
        // ceil(2.25) = 3 — the assertion distinguishes round from ceil
        $bar = self::makeBar(barWidth: 10);
        $frame = new Frame(10, 1);

        $bar->render($frame, Rect::fromSize(10, 1), self::contextAt(0.25));

        self::assertSame('█', $frame->characters[2], 'round(2.25) = 2, not ceil(2.25)=3');
        self::assertSame('▓', $frame->characters[3]);
    }

    #[Test]
    public function allColumnsWithinBarWidthAreRendered(): void
    {
        $bar = self::makeBar(barWidth: 6);
        $frame = new Frame(10, 1);
        $context = new RenderContext(Theme::dark());

        $bar->render($frame, Rect::fromSize(10, 1), $context);

        for ($column = 0; $column < 6; ++$column) {
            self::assertNotSame(' ', $frame->characters[$column], "Column $column should be rendered");
        }
        self::assertSame(' ', $frame->characters[6], 'Column after barWidth should be empty');
    }

    #[Test]
    public function loopDoesNotRenderBeyondBarWidth(): void
    {
        $bar = self::makeBar(barWidth: 3);
        $frame = new Frame(10, 1);
        $context = new RenderContext(Theme::dark());

        $bar->render($frame, Rect::fromSize(10, 1), $context);

        self::assertNotSame(' ', $frame->characters[0]);
        self::assertNotSame(' ', $frame->characters[1]);
        self::assertNotSame(' ', $frame->characters[2]);
        self::assertSame(' ', $frame->characters[3], 'Column 3 must be empty with barWidth=3');
    }

    #[Test]
    public function gradientDistanceUsesSubtractionNotAddition(): void
    {
        $bar = self::makeBar(barWidth: 10);
        $frame = new Frame(10, 1);

        $bar->render($frame, Rect::fromSize(10, 1), self::contextAt(0.5));

        // center = round(0.5 * 9) = 5
        self::assertSame('█', $frame->characters[5], 'Center column has distance 0');
        self::assertSame('▓', $frame->characters[4], 'Column before center has distance 1');
        self::assertSame('▓', $frame->characters[6], 'Column after center has distance 1');
        self::assertSame('▒', $frame->characters[3], 'Two columns before center has distance 2');
    }

    #[Test]
    public function availableBarWidthIsAreaWidthMinusLabelWidth(): void
    {
        $bar = self::makeBar(label: 'AB', barWidth: 50);
        $frame = new Frame(30, 1);
        $context = new RenderContext(Theme::dark());

        // label "AB" has labelWidth = 2 + 1 = 3
        // area width = 8, availableBarWidth = 8 - 3 = 5, barWidth = min(50, 5) = 5
        $bar->render($frame, new Rect(0, 0, 8, 1), $context);

        self::assertNotSame(' ', $frame->characters[7], 'Last column (index 7) within barWidth should be rendered');
        self::assertSame(' ', $frame->characters[8], 'Column 8 should be empty (beyond area width 8)');
    }

    #[Test]
    public function labelWithTightAreaDoesNotRenderBar(): void
    {
        $bar = self::makeBar(label: 'ABCDE', barWidth: 5);
        $frame = new Frame(6, 1);
        $context = new RenderContext(Theme::dark());

        // labelWidth = 5 + 1 = 6, availableBarWidth = 6 - 6 = 0, barWidth = min(5, 0) = 0
        $bar->render($frame, Rect::fromSize(6, 1), $context);

        self::assertSame('A', $frame->characters[0]);
        self::assertSame('E', $frame->characters[4]);
        self::assertSame(' ', $frame->characters[5], 'Space after label, but no bar rendered');
    }

    #[Test]
    public function labelOnlyRenderRequestsNoRepaint(): void
    {
        $bar = self::makeBar(label: 'ABCDE', barWidth: 5);
        $frame = new Frame(6, 1);
        $pass = RenderPass::new(Theme::dark());

        $bar->render($frame, Rect::fromSize(6, 1), $pass->context);

        self::assertNull($pass->clock->repaintDelay(), 'Without a visible bar there is nothing to animate');
    }

    #[Test]
    public function centerColumnAtPositionOneWithBarWidthOne(): void
    {
        $bar = self::makeBar(barWidth: 1);
        $frame = new Frame(5, 1);

        $bar->render($frame, Rect::fromSize(5, 1), self::contextAt(1.0));

        // centerColumn = round(1.0 * (1 - 1)) = 0
        self::assertSame('█', $frame->characters[0], 'Center at 0 even with position=1.0 when barWidth=1');
    }

    #[Test]
    public function labelWidthInitializesToZeroForUnlabeledBar(): void
    {
        // area.width=22, labelWidth=0, availableBarWidth = 22 - 0 = 22, barWidth = min(25, 22) = 22
        $bar = self::makeBar(barWidth: 25);
        $frame = new Frame(25, 1);
        $context = new RenderContext(Theme::dark());

        $bar->render($frame, Rect::fromSize(22, 1), $context);

        self::assertNotSame(' ', $frame->characters[21], 'Column 21 rendered (last of barWidth=22)');
        self::assertSame(' ', $frame->characters[22], 'Column 22 empty (beyond available width)');
    }

    #[Test]
    public function withBarWidthReturnsNewInstanceWithUpdatedWidth(): void
    {
        $bar = ActivityBar::new();
        $modified = $bar->withBarWidth(10);

        $frame = new Frame(15, 1);
        $context = new RenderContext(Theme::dark());
        $modified->render($frame, Rect::fromSize(15, 1), $context);

        for ($column = 0; $column < 10; ++$column) {
            self::assertNotSame(' ', $frame->characters[$column], "Column $column within barWidth=10");
        }
        self::assertSame(' ', $frame->characters[10], 'Column 10 should be empty with barWidth=10');
    }

    #[Test]
    public function dimColorUsedForTrackCharacters(): void
    {
        $bar = ActivityBar::new();
        $theme = Theme::dark();
        $frame = new Frame(20, 1);

        $bar->render($frame, Rect::fromSize(20, 1), new RenderContext($theme));

        $dimFg = $theme->dim->foregroundValue();
        self::assertSame($dimFg, $frame->foregrounds[10], 'Track characters use dim foreground color');
    }

    /**
     * With speed 1.0 the highlight position equals the frame time during the
     * first sweep (0.0 → 1.0), which keeps the test math readable.
     */
    private static function makeBar(
        string $label = '',
        float $speed = 1.0,
        int $barWidth = 20,
    ): ActivityBar {
        return ActivityBar::new($label)
            ->withSpeed($speed)
            ->withBarWidth($barWidth);
    }

    private static function contextAt(float $now): RenderContext
    {
        return self::passAt($now)->context;
    }

    private static function passAt(float $now): RenderPass
    {
        $pass = RenderPass::new(Theme::dark());
        $pass->begin($now);

        return $pass;
    }
}
