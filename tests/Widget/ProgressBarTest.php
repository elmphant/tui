<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseButton;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Message\TickMsg;
use Elmphant\Tui\Rendering\AnsiColor;
use Elmphant\Tui\Rendering\Attr;
use Elmphant\Tui\Rendering\Color;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Style;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Widget\Feedback\ProgressBar;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProgressBarTest extends TestCase
{
    private RenderContext $context;

    /**
     * The builder clamps, so only the constructor can hold a share above one —
     * and the fill is drawn from it, past the end of the area it was given.
     */
    #[Test]
    public function aShareAboveOneDoesNotPaintPastTheAreaGiven(): void
    {
        $frame = new Frame(14, 1);

        ProgressBar::new(2.5)->render($frame, new Rect(2, 0, 10, 1), $this->context);

        self::assertSame(' ', $frame->characters[12]);
    }

    #[Test]
    public function constraintReturnsExactOne(): void
    {
        $progressBar = ProgressBar::new();
        $constraint = $progressBar->sizing()->rows;
        self::assertSame(1, $constraint->min);
        self::assertSame(1, $constraint->max);
    }

    #[Test]
    public function aProgressThatIsNotANumberCountsAsNone(): void
    {
        self::assertSame([0.0, 0.0], [ProgressBar::new(NAN)->progress(), ProgressBar::new()->withProgress(NAN)->progress()]);
    }

    #[Test]
    public function withProgressClampsToZeroOne(): void
    {
        $progressBar = ProgressBar::new();

        $clamped = $progressBar->withProgress(1.5);
        self::assertSame(1.0, $clamped->progress());

        $clamped = $progressBar->withProgress(-0.5);
        self::assertSame(0.0, $clamped->progress());

        $normal = $progressBar->withProgress(0.75);
        self::assertSame(0.75, $normal->progress());
    }

    #[Test]
    public function aBarThatWasNeverAnimatedDrawsItsActualProgress(): void
    {
        $progressBar = ProgressBar::new()
            ->withProgress(0.5)
            ->withPercentShown(false);

        $frame = new Frame(20, 1);
        $progressBar->render($frame, new Rect(0, 0, 20, 1), $this->context);

        $filled = 0;
        for ($column = 0; $column < 20; ++$column) {
            if ($frame->characters[$column] === '█') {
                ++$filled;
            }
        }

        self::assertSame(10, $filled, 'An inline bar has no tick loop, so it must draw the truth');
    }

    #[Test]
    public function theFirstTickNeverJerksTheBarBackwards(): void
    {
        $progressBar = ProgressBar::new()->withProgress(0.8);

        $ticked = $progressBar->update(new TickMsg(0.0), Rect::fromSize(20, 1))->model;

        self::assertInstanceOf(ProgressBar::class, $ticked);
        self::assertSame(0.8, $ticked->displayValue(), 'A bar already drawn at 80% must not restart from empty');
    }

    #[Test]
    public function aChangeAfterTheBarHasSettledStillAnimates(): void
    {
        $settled = ProgressBar::new()->withProgress(0.2)
            ->update(new TickMsg(0.0), Rect::fromSize(20, 1))->model;

        self::assertInstanceOf(ProgressBar::class, $settled);

        $moving = $settled->withProgress(1.0)->update(new TickMsg(0.0), Rect::fromSize(20, 1))->model;

        self::assertInstanceOf(ProgressBar::class, $moving);
        self::assertGreaterThan(0.2, $moving->displayValue());
        self::assertLessThan(1.0, $moving->displayValue(), 'The spring still eases toward the new target');
    }

    #[Test]
    public function withLabelReturnsNewInstance(): void
    {
        $progressBar = ProgressBar::new();
        $labeled = $progressBar->withLabel('Loading');
        self::assertNotSame($progressBar, $labeled);
    }

    #[Test]
    public function rendersFillAndEmptyBlocks(): void
    {
        $progressBar = ProgressBar::new()
            ->withProgress(0.5)
            ->withPercentShown(false);

        $frame = new Frame(20, 1);
        $area = new Rect(0, 0, 20, 1);
        $progressBar->render($frame, $area, $this->context);

        $fullBlockCount = 0;
        $emptyBlockCount = 0;
        for ($column = 0; $column < 20; ++$column) {
            if ($frame->characters[$column] === '█') {
                ++$fullBlockCount;
            } elseif ($frame->characters[$column] === '─') {
                ++$emptyBlockCount;
            }
        }

        self::assertSame(10, $fullBlockCount);
        self::assertGreaterThan(0, $emptyBlockCount);
    }

    #[Test]
    public function rendersLabelWhenSet(): void
    {
        $progressBar = ProgressBar::new()
            ->withLabel('DL')
            ->withProgress(0.0)
            ->withPercentShown(false);

        $frame = new Frame(20, 1);
        $area = new Rect(0, 0, 20, 1);
        $progressBar->render($frame, $area, $this->context);

        self::assertSame('D', $frame->characters[0]);
        self::assertSame('L', $frame->characters[1]);
    }

    #[Test]
    public function springAnimationDisplayApproachesTarget(): void
    {
        $progressBar = ProgressBar::new()->withDisplay(0.0)->withProgress(1.0);
        self::assertSame(0.0, $progressBar->displayValue());

        $area = Rect::fromSize(20, 1);
        $tickMessage = new TickMsg(microtime(true));

        $result = $progressBar->update($tickMessage, $area);
        $updated = $result->model;
        self::assertInstanceOf(ProgressBar::class, $updated);
        self::assertGreaterThan(0.0, $updated->displayValue());
        self::assertLessThan(1.0, $updated->displayValue());
    }

    #[Test]
    public function theSpringMovesByTimeNotByHowOftenItIsTicked(): void
    {
        $area = Rect::fromSize(20, 1);
        $rarely = ProgressBar::new()->withDisplay(0.0)->withProgress(0.8);
        $often = $rarely;

        for ($tick = 0; $tick <= 3; ++$tick) {
            $rarely = $rarely->update(new TickMsg($tick * 0.05), $area)->model;
        }
        for ($tick = 0; $tick <= 30; ++$tick) {
            $often = $often->update(new TickMsg($tick * 0.005), $area)->model;
        }

        self::assertEqualsWithDelta($often->displayValue(), $rarely->displayValue(), 0.01);
        self::assertLessThan(0.8, $rarely->displayValue());
    }

    #[Test]
    public function springAnimationMovesGradually(): void
    {
        $progressBar = ProgressBar::new()->withDisplay(0.0)->withProgress(1.0);
        $area = Rect::fromSize(50, 1);
        $result = $progressBar->update(new TickMsg(0.0), $area);
        self::assertLessThan(0.5, $result->model->displayValue(),
            'Spring should move gradually, not jump 85% on first tick');
    }

    #[Test]
    public function springAnimationSnapsWhenClose(): void
    {
        $progressBar = ProgressBar::new()->withProgress(0.5);
        $area = Rect::fromSize(20, 1);
        $tickMessage = new TickMsg(microtime(true));

        $current = $progressBar;
        for ($iteration = 0; $iteration < 100; ++$iteration) {
            $result = $current->update($tickMessage, $area);
            $current = $result->model;
        }

        self::assertSame(0.5, $current->displayValue());
    }

    #[Test]
    public function clickSetsProgressFromMousePosition(): void
    {
        $progressBar = ProgressBar::new()->withProgress(0.0)->withClickable();
        $area = new Rect(0, 0, 21, 1);
        $mouseMessage = new MouseMsg(10, 0, MouseAction::Press, MouseButton::Left);

        $result = $progressBar->update($mouseMessage, $area);
        $updated = $result->model;
        self::assertInstanceOf(ProgressBar::class, $updated);
        self::assertSame(0.5, $updated->progress());
    }

    #[Test]
    public function aClickOnTheLastCellFillsTheBar(): void
    {
        $progressBar = ProgressBar::new()->withProgress(0.0)->withClickable();

        $result = $progressBar->update(new MouseMsg(19, 0, MouseAction::Press, MouseButton::Left), new Rect(0, 0, 20, 1));

        self::assertSame(1.0, $result->model->progress());
    }

    #[Test]
    public function aClickOnABarOfOneCellFillsIt(): void
    {
        $progressBar = ProgressBar::new()->withProgress(0.0)->withClickable();

        $result = $progressBar->update(new MouseMsg(4, 0, MouseAction::Press, MouseButton::Left), new Rect(4, 0, 1, 1));

        self::assertSame(1.0, $result->model->progress());
    }

    #[Test]
    public function clickOutsideAreaDoesNothing(): void
    {
        $progressBar = ProgressBar::new()->withProgress(0.0)->withClickable();
        $area = new Rect(0, 0, 20, 1);
        $mouseMessage = new MouseMsg(10, 5, MouseAction::Press, MouseButton::Left);

        $result = $progressBar->update($mouseMessage, $area);
        self::assertSame($progressBar, $result->model);
    }

    #[Test]
    public function aBarTakesNoClickUnlessAskedTo(): void
    {
        $progressBar = ProgressBar::new()->withProgress(0.0);
        $area = new Rect(0, 0, 20, 1);
        $mouseMessage = new MouseMsg(10, 0, MouseAction::Press, MouseButton::Left);

        $result = $progressBar->update($mouseMessage, $area);
        self::assertSame($progressBar, $result->model);
    }

    #[Test]
    public function renderWithZeroWidthDoesNotCrash(): void
    {
        $progressBar = ProgressBar::new()->withProgress(0.5);

        $frame = new Frame(20, 1);
        $area = new Rect(0, 0, 0, 1);
        $progressBar->render($frame, $area, $this->context);

        self::assertSame(' ', $frame->characters[0]);
    }

    #[Test]
    public function renderWithZeroHeightDoesNotCrash(): void
    {
        $progressBar = ProgressBar::new()->withProgress(0.5);

        $frame = new Frame(20, 1);
        $area = new Rect(0, 0, 20, 0);
        $progressBar->render($frame, $area, $this->context);

        self::assertSame(' ', $frame->characters[0]);
    }

    #[Test]
    public function percentageRenderedWhenEnabled(): void
    {
        $progressBar = ProgressBar::new()
            ->withProgress(1.0)
            ->withPercentShown(true);

        $frame = new Frame(20, 1);
        $area = new Rect(0, 0, 20, 1);
        $progressBar->render($frame, $area, $this->context);

        $row = '';
        for ($column = 0; $column < 20; ++$column) {
            $row .= $frame->characters[$column];
        }

        self::assertStringContainsString('100%', $row);
    }

    #[Test]
    public function withProgressClampsAboveOne(): void
    {
        $bar = ProgressBar::new()->withProgress(1.5);
        self::assertSame(1.0, $bar->progress());
    }

    #[Test]
    public function withProgressClampsBelowZero(): void
    {
        $bar = ProgressBar::new()->withProgress(-0.5);
        self::assertSame(0.0, $bar->progress());
    }

    #[Test]
    public function aClickableBarCanBeMadeStillAgain(): void
    {
        $bar = ProgressBar::new()->withClickable()->withClickable(false);
        $area = new Rect(0, 0, 50, 1);
        $click = new MouseMsg(25, 0, MouseAction::Press, MouseButton::Left);
        $result = $bar->update($click, $area);
        self::assertSame(0.0, $result->model->progress());
    }

    // --- Mutant-killing tests below ---

    #[Test]
    public function theDefaultBarShowsItsPercent(): void
    {
        $progressBar = ProgressBar::new()->withProgress(0.5);

        $frame = new Frame(20, 1);
        $area = new Rect(0, 0, 20, 1);
        $progressBar->render($frame, $area, $this->context);

        $row = $this->extractRow($frame, 0, 20);
        self::assertStringContainsString('50%', $row);
    }

    /**
     * Once the animation state exists, a tick that moves nothing must not report
     * a change, or every settled bar costs a repaint per frame. The very first
     * tick is exempt: it records where the spring should start from.
     */
    #[Test]
    public function tickReturnsSameInstanceWhenDisplayEqualsProgress(): void
    {
        $area = Rect::fromSize(20, 1);
        $settled = ProgressBar::new()->withProgress(0.5)->update(new TickMsg(0.0), $area)->model;

        self::assertInstanceOf(ProgressBar::class, $settled);
        self::assertSame(0.5, $settled->displayValue());
        self::assertSame($settled, $settled->update(new TickMsg(0.0), $area)->model);
    }

    /**
     * L98: LessThan — tests behavior when difference is exactly at the 0.001 boundary.
     * When abs(difference) === 0.001, the < check means the spring should still animate,
     * not snap. If mutated to <=, the snap would happen too early.
     */
    #[Test]
    public function tickDoesNotSnapWhenDifferenceIsExactlyThreshold(): void
    {
        // Create a bar where display is exactly 0.001 away from progress
        // progress=0.501, display=0.500 => difference=0.001
        // abs(0.001) < 0.001 is false, so it should NOT snap — should apply springFactor
        $progressBar = ProgressBar::new(0.501)->withDisplay(0.500);

        $area = Rect::fromSize(20, 1);
        $result = $progressBar->update(new TickMsg(0.0), $area);

        // With springFactor=0.15, display += 0.001 * 0.15 = 0.50015
        // If mutated to <=, it would snap to 0.501 instead
        $updated = $result->model;
        self::assertNotSame(0.501, $updated->displayValue());
        self::assertGreaterThan(0.500, $updated->displayValue());
        self::assertLessThan(0.501, $updated->displayValue());
    }

    /**
     * L98/L100: When difference is tiny but display !== progress, snap display to progress.
     */
    #[Test]
    public function tickSnapsDisplayToProgressWhenVeryClose(): void
    {
        // difference = 0.0005, abs < 0.001 is true, but display !== progress => snap
        $progressBar = ProgressBar::new(0.5005)->withDisplay(0.5000);

        $area = Rect::fromSize(20, 1);
        $result = $progressBar->update(new TickMsg(0.0), $area);
        self::assertSame(0.5005, $result->model->displayValue());
    }

    #[Test]
    public function clickAccountsForAreaOffset(): void
    {
        $progressBar = ProgressBar::new()->withProgress(0.0)->withClickable();
        $area = new Rect(10, 0, 21, 1);
        $click = new MouseMsg(20, 0, MouseAction::Press, MouseButton::Left);
        $result = $progressBar->update($click, $area);
        self::assertSame(0.5, $result->model->progress());
    }

    /**
     * L112: Minus — verify that without subtraction, position would be wrong.
     */
    #[Test]
    public function clickAtAreaStartSetsProgressToZero(): void
    {
        $progressBar = ProgressBar::new()->withProgress(0.5)->withClickable();
        $area = new Rect(5, 0, 20, 1);
        // Click at start of area (x=5) => localX=0 => fraction=0
        $click = new MouseMsg(5, 0, MouseAction::Press, MouseButton::Left);
        $result = $progressBar->update($click, $area);
        self::assertSame(0.0, $result->model->progress());
    }

    /**
     * L122: LessThanOrEqualTo — $area->width <= 0.
     * Width=1 must render (not bail out).
     */
    #[Test]
    public function renderWithWidthOneProducesOutput(): void
    {
        $progressBar = ProgressBar::new()
            ->withProgress(1.0)
            ->withPercentShown(false);

        $frame = new Frame(5, 1);
        $area = new Rect(0, 0, 1, 1);
        $progressBar->render($frame, $area, $this->context);

        self::assertSame('█', $frame->characters[0]);
    }

    /**
     * L130: LessThanOrEqualTo — $barWidth <= 0.
     * When label consumes almost all width, barWidth=1 must still render.
     */
    #[Test]
    public function renderWithLabelLeavingOneColumnForBar(): void
    {
        // label "AB" => labelWidth = mb_strlen("AB") + 1 = 3
        // area width = 4, barWidth = 4 - 3 = 1
        $progressBar = ProgressBar::new()
            ->withLabel('AB')
            ->withProgress(1.0)
            ->withPercentShown(false);

        $frame = new Frame(10, 1);
        $area = new Rect(0, 0, 4, 1);
        $progressBar->render($frame, $area, $this->context);

        // Label occupies [0,1], space at [2], bar at [3]
        self::assertSame('A', $frame->characters[0]);
        self::assertSame('B', $frame->characters[1]);
        self::assertSame('█', $frame->characters[3]);
    }

    /**
     * L135: Coalesce — fillStyleOverride ?? theme->fillStyle.
     * Custom fill style must be used instead of theme default.
     */
    #[Test]
    public function customFillStyleOverridesTheme(): void
    {
        $customColor = Color::rgb(255, 0, 0);
        $customFillStyle = new Style(foreground: $customColor);

        $progressBar = ProgressBar::new()
            ->withProgress(1.0)
            ->withFillStyle($customFillStyle)
            ->withPercentShown(false);

        $frame = new Frame(10, 1);
        $area = new Rect(0, 0, 10, 1);
        $progressBar->render($frame, $area, $this->context);

        self::assertSame($customColor->value, $frame->foregrounds[0]);
    }

    #[Test]
    public function thePercentReadsOnWhateverFillItSitsOn(): void
    {
        $fill = Color::hex('#00008b');

        $progressBar = ProgressBar::new()
            ->withProgress(0.9)
            ->withFillStyle(new Style(foreground: $fill))
            ->withPercentShown(true);

        $frame = new Frame(20, 1);
        $progressBar->render($frame, new Rect(0, 0, 20, 1), $this->context);

        $percentIndex = $this->findCharIndex($frame, '%', 20);
        self::assertNotNull($percentIndex);
        self::assertGreaterThanOrEqual(
            4.5,
            Color::fromValue($frame->foregrounds[$percentIndex])->contrastRatio($fill),
            'the number is read off the fill it is drawn on, so the fill decides the ink',
        );
    }

    /**
     * L136: Coalesce — emptyStyleOverride ?? theme->emptyStyle.
     * Custom empty style must be used instead of theme default.
     */
    #[Test]
    public function customEmptyStyleOverridesTheme(): void
    {
        $customColor = Color::rgb(0, 255, 0);
        $customEmptyStyle = new Style(foreground: $customColor);

        $progressBar = ProgressBar::new()
            ->withProgress(0.0)
            ->withEmptyStyle($customEmptyStyle)
            ->withPercentShown(false);

        $frame = new Frame(10, 1);
        $area = new Rect(0, 0, 10, 1);
        $progressBar->render($frame, $area, $this->context);

        // All cells should be empty dashes with our custom color
        self::assertSame('─', $frame->characters[0]);
        self::assertSame($customColor->value, $frame->foregrounds[0]);
    }

    /**
     * L144: GreaterThan + LogicalAnd — label rendering condition.
     * Label must only render when label is non-empty AND area is wider than bar.
     */
    #[Test]
    public function labelNotRenderedWhenEmpty(): void
    {
        $progressBar = ProgressBar::new()
            ->withProgress(0.0)
            ->withPercentShown(false);

        $frame = new Frame(10, 1);
        $area = new Rect(0, 0, 10, 1);
        $progressBar->render($frame, $area, $this->context);

        // Without a label, position 0 should be a bar character, not text
        self::assertSame('─', $frame->characters[0]);
    }

    /**
     * L144: LogicalAnd — when label exists, area width must be greater than barWidth.
     * A label that consumes ALL width means barWidth=0 => label still renders
     * because area->width > barWidth (which is 0).
     */
    #[Test]
    public function labelRenderedWhenAreaWiderThanBar(): void
    {
        $progressBar = ProgressBar::new()
            ->withLabel('X')
            ->withProgress(0.5)
            ->withPercentShown(false);

        $frame = new Frame(20, 1);
        $area = new Rect(0, 0, 20, 1);
        $progressBar->render($frame, $area, $this->context);

        self::assertSame('X', $frame->characters[0]);
    }

    /**
     * L149: CastInt — (int) $fillFraction.
     * L151: CastInt, Multiplication, RoundingFamily, DecrementInteger, IncrementInteger.
     * L153: LessThan, DecrementInteger — loop $column < $barWidth.
     * L155: LessThanNegotiation — $column < $fullCells.
     *
     * At 100% progress on a 10-cell bar, all 10 cells must be full blocks.
     */
    #[Test]
    public function progressBarFilledWidthMatchesPercentage(): void
    {
        $progressBar = ProgressBar::new()
            ->withProgress(1.0)
            ->withPercentShown(false);

        $frame = new Frame(10, 1);
        $area = new Rect(0, 0, 10, 1);
        $progressBar->render($frame, $area, $this->context);

        for ($column = 0; $column < 10; ++$column) {
            self::assertSame('█', $frame->characters[$column], "Column $column should be a full block");
        }
    }

    /**
     * L149/L151/L155: At 0% progress, all cells must be empty dashes.
     */
    #[Test]
    public function zeroProgressRendersAllEmptyDashes(): void
    {
        $progressBar = ProgressBar::new()
            ->withProgress(0.0)
            ->withPercentShown(false);

        $frame = new Frame(10, 1);
        $area = new Rect(0, 0, 10, 1);
        $progressBar->render($frame, $area, $this->context);

        for ($column = 0; $column < 10; ++$column) {
            self::assertSame('─', $frame->characters[$column], "Column $column should be an empty dash");
        }
    }

    /**
     * L151: RoundingFamily — (int)round($remainder * 8).
     * L157: GreaterThan, Identical, LogicalAnd, LogicalAndAllSubExprNegation, GreaterThanNegotiation.
     *
     * A fractional progress (e.g., 0.3 on a 10-cell bar) should produce a partial block character.
     * fillFraction = 0.3 * 10 = 3.0, fullCells = 3, remainder = 0.0, fractionIndex = 0.
     * So we need a value that produces a non-zero remainder.
     * Use display=0.35 on width=10: fillFraction=3.5, fullCells=3, remainder=0.5,
     * fractionIndex=(int)round(0.5*8)=(int)round(4.0)=4 => BLOCKS[4]='▌'
     */
    #[Test]
    public function fractionalProgressRendersFractionalBlock(): void
    {
        $progressBar = ProgressBar::new(0.35)->withDisplay(0.35)->withPercentShown(false);

        $frame = new Frame(10, 1);
        $area = new Rect(0, 0, 10, 1);
        $progressBar->render($frame, $area, $this->context);

        // fullCells=3 => columns 0,1,2 are full blocks
        self::assertSame('█', $frame->characters[0]);
        self::assertSame('█', $frame->characters[1]);
        self::assertSame('█', $frame->characters[2]);

        // Column 3 is the fractional block (▌)
        $fractionalBlock = $frame->characters[3];
        self::assertNotSame('█', $fractionalBlock);
        self::assertNotSame('─', $fractionalBlock);
        self::assertNotSame(' ', $fractionalBlock);
        self::assertSame('▌', $fractionalBlock);

        // Columns 4-9 are empty dashes
        for ($column = 4; $column < 10; ++$column) {
            self::assertSame('─', $frame->characters[$column], "Column $column should be empty dash");
        }
    }

    /**
     * L157: LogicalAnd — $column === $fullCells && $fractionIndex > 0.
     * When remainder is 0 (exact progress), no fractional block should appear.
     */
    #[Test]
    public function exactProgressProducesNoFractionalBlock(): void
    {
        // display=0.5 on width=10: fillFraction=5.0, fullCells=5, remainder=0.0, fractionIndex=0
        $progressBar = ProgressBar::new(0.5)->withDisplay(0.5)->withPercentShown(false);

        $frame = new Frame(10, 1);
        $area = new Rect(0, 0, 10, 1);
        $progressBar->render($frame, $area, $this->context);

        // Columns 0-4 are full blocks
        for ($column = 0; $column < 5; ++$column) {
            self::assertSame('█', $frame->characters[$column]);
        }
        // Column 5 should be an empty dash (not a fractional block)
        self::assertSame('─', $frame->characters[5]);
    }

    /**
     * L165: RoundingFamily — (int)round($this->display * 100).
     * Verify that rounding is correct: display=0.005 => 1%, display=0.004 => 0%.
     */
    #[Test]
    public function percentageTextUsesRounding(): void
    {
        // display=0.005 => round(0.5)=1 (banker's rounding: 0), but round(0.005*100)=round(0.5)=0
        // Actually in PHP, round(0.5) = 0 (PHP_ROUND_HALF_EVEN in PHP8.4, but in 8.3 it's 1).
        // Let's use a clear case: display=0.155 => round(15.5) => 16%
        // Or simpler: display=0.339 => round(33.9) = 34
        $progressBar = ProgressBar::new(0.339)->withDisplay(0.339)->withPercentShown(true);

        $frame = new Frame(20, 1);
        $area = new Rect(0, 0, 20, 1);
        $progressBar->render($frame, $area, $this->context);

        $row = $this->extractRow($frame, 0, 20);
        self::assertStringContainsString('34%', $row);
    }

    /**
     * L165: RoundingFamily — verify floor vs round distinction.
     * display=0.346 => round(34.6) = 35, not 34 (floor).
     */
    #[Test]
    public function percentageTextRoundsUpWhenAppropriate(): void
    {
        $progressBar = ProgressBar::new(0.346)->withDisplay(0.346)->withPercentShown(true);

        $frame = new Frame(20, 1);
        $area = new Rect(0, 0, 20, 1);
        $progressBar->render($frame, $area, $this->context);

        $row = $this->extractRow($frame, 0, 20);
        self::assertStringContainsString('35%', $row);
        self::assertStringNotContainsString('34%', $row);
    }

    /**
     * L167: GreaterThanOrEqualTo — $percentX >= $barArea->x.
     * On a very narrow bar where percent text won't fit, it should not render.
     */
    #[Test]
    public function percentNotRenderedWhenBarTooNarrow(): void
    {
        // percentText = " 50%" (4 chars), bar width = 3 => percentX = 3 - 4 = -1 < barArea->x(0)
        $progressBar = ProgressBar::new(0.5)->withDisplay(0.5)->withPercentShown(true);

        $frame = new Frame(10, 1);
        $area = new Rect(0, 0, 3, 1);
        $progressBar->render($frame, $area, $this->context);

        $row = $this->extractRow($frame, 0, 3);
        self::assertStringNotContainsString('%', $row);
    }

    /**
     * L167: GreaterThanOrEqualTo — When percentX equals barArea->x exactly, it should render.
     * Bar width = 4 (same as percent text length " 50%" = 4), percentX = 0 >= 0 => renders.
     */
    #[Test]
    public function percentRenderedWhenExactlyFitting(): void
    {
        $progressBar = ProgressBar::new(0.5)->withDisplay(0.5)->withPercentShown(true);

        $frame = new Frame(10, 1);
        $area = new Rect(0, 0, 4, 1);
        $progressBar->render($frame, $area, $this->context);

        $row = $this->extractRow($frame, 0, 4);
        self::assertStringContainsString('50%', $row);
    }

    /**
     * L168: GreaterThan, DecrementInteger, IncrementInteger, Ternary — percent foreground color.
     * L169: GreaterThan, Ternary — percent background color.
     * When display > 0.5, percent sits on the fill and takes the ink that fill
     * contrasts with — ansi(0) for this theme. When display <= 0.5, it uses
     * labelColor foreground and baseBackground.
     */
    #[Test]
    public function percentColorChangesAboveHalfProgress(): void
    {
        $theme = Theme::dark();
        $fillColor = $theme->fillStyle->foregroundValue();
        $labelColor = $theme->base->foregroundValue();
        $baseBackground = $theme->base->backgroundValue();
        $ansi0Color = Color::ansi(AnsiColor::Black)->value;

        // display = 0.6 (> 0.5) => dark text on fill background
        $barAboveHalf = ProgressBar::new(0.6)->withDisplay(0.6)->withPercentShown(true);

        $frame = new Frame(20, 1);
        $area = new Rect(0, 0, 20, 1);
        $barAboveHalf->render($frame, $area, $this->context);

        // Percent text is at the right end. Find the '%' character.
        $percentIndex = $this->findCharIndex($frame, '%', 20);
        self::assertNotNull($percentIndex, 'Should find % character');
        self::assertSame($ansi0Color, $frame->foregrounds[$percentIndex]);
        self::assertSame($fillColor, $frame->backgrounds[$percentIndex]);

        // display = 0.4 (<= 0.5) => labelColor text on baseBackground
        $barBelowHalf = ProgressBar::new(0.4)->withDisplay(0.4)->withPercentShown(true);

        $buffer2 = new Frame(20, 1);
        $barBelowHalf->render($buffer2, $area, $this->context);

        $percentIndex2 = $this->findCharIndex($buffer2, '%', 20);
        self::assertNotNull($percentIndex2, 'Should find % character');
        self::assertSame($labelColor, $buffer2->foregrounds[$percentIndex2]);
        self::assertSame($baseBackground, $buffer2->backgrounds[$percentIndex2]);
    }

    /**
     * L168/L169: Boundary test at exactly display=0.5 — should use labelColor, not ansi(0).
     */
    #[Test]
    public function percentColorAtExactlyHalfUsesLabelColor(): void
    {
        $theme = Theme::dark();
        $labelColor = $theme->base->foregroundValue();
        $ansi0Color = Color::ansi(AnsiColor::Black)->value;

        $progressBar = ProgressBar::new(0.5)->withDisplay(0.5)->withPercentShown(true);

        $frame = new Frame(20, 1);
        $area = new Rect(0, 0, 20, 1);
        $progressBar->render($frame, $area, $this->context);

        $percentIndex = $this->findCharIndex($frame, '%', 20);
        self::assertNotNull($percentIndex);
        self::assertSame($labelColor, $frame->foregrounds[$percentIndex]);
        self::assertNotSame($ansi0Color, $frame->foregrounds[$percentIndex]);
    }

    /**
     * L178: IncrementInteger — barRect returns area unchanged when label is empty.
     * The bar should span the full width when there is no label.
     */
    #[Test]
    public function barRectUsesFullWidthWithoutLabel(): void
    {
        $progressBar = ProgressBar::new()
            ->withProgress(1.0)
            ->withPercentShown(false);

        $frame = new Frame(10, 1);
        $area = new Rect(2, 0, 8, 1);
        $progressBar->render($frame, $area, $this->context);

        // Full blocks start at x=2 and fill all 8 columns
        for ($column = 2; $column < 10; ++$column) {
            self::assertSame('█', $frame->characters[$column], "Column $column should be full block");
        }
        // Before the area, buffer should be blank
        self::assertSame(' ', $frame->characters[0]);
        self::assertSame(' ', $frame->characters[1]);
    }

    /**
     * L180: MBString, DecrementInteger, IncrementInteger — mb_strlen($this->label) + 1.
     * L181: DecrementInteger x2, IncrementInteger x2 — offset and width calculations.
     *
     * Label "Hi" (2 chars) + 1 space = 3 columns reserved.
     * Area width 13 => barWidth = 13 - 3 = 10.
     */
    #[Test]
    public function labelOffsetCalculation(): void
    {
        $progressBar = ProgressBar::new()
            ->withLabel('Hi')
            ->withProgress(1.0)
            ->withPercentShown(false);

        $frame = new Frame(13, 1);
        $area = new Rect(0, 0, 13, 1);
        $progressBar->render($frame, $area, $this->context);

        // Label: "Hi" at [0,1], space at [2]
        self::assertSame('H', $frame->characters[0]);
        self::assertSame('i', $frame->characters[1]);

        // Bar starts at column 3, spans 10 columns (all full blocks)
        for ($column = 3; $column < 13; ++$column) {
            self::assertSame('█', $frame->characters[$column], "Column $column should be full block");
        }
    }

    /**
     * L180: MBString — mb_strlen vs strlen distinction with multibyte label.
     */
    #[Test]
    public function multibyteLabelOffsetCalculation(): void
    {
        // "日本" is 2 characters (mb_strlen=2), but 6 bytes (strlen=6)
        // labelWidth = mb_strlen("日本") + 1 = 3
        // But note: each CJK char is 2 columns wide in terminal, so mb_strlen gives 2.
        // labelWidth = 2 + 1 = 3
        $progressBar = ProgressBar::new()
            ->withLabel('AB')
            ->withProgress(1.0)
            ->withPercentShown(false);

        $frame = new Frame(13, 1);
        $area = new Rect(0, 0, 13, 1);
        $progressBar->render($frame, $area, $this->context);

        // mb_strlen("AB") = 2, labelWidth = 3
        // Bar starts at column 3 with width 10
        self::assertSame('A', $frame->characters[0]);
        self::assertSame('B', $frame->characters[1]);

        $fullBlocks = 0;
        for ($column = 3; $column < 13; ++$column) {
            if ($frame->characters[$column] === '█') {
                ++$fullBlocks;
            }
        }
        self::assertSame(10, $fullBlocks);
    }

    /**
     * L181: Test that bar area x-offset accounts for area.x + labelWidth.
     */
    #[Test]
    public function labelWithNonZeroAreaOffset(): void
    {
        $progressBar = ProgressBar::new()
            ->withLabel('A')
            ->withProgress(1.0)
            ->withPercentShown(false);

        $frame = new Frame(20, 1);
        // Area starts at x=5
        $area = new Rect(5, 0, 10, 1);
        $progressBar->render($frame, $area, $this->context);

        // Label "A" at x=5, space at x=6
        // labelWidth = mb_strlen("A") + 1 = 2
        // barArea starts at x = 5 + 2 = 7, width = 10 - 2 = 8
        self::assertSame('A', $frame->characters[5]);

        // Bar starts at column 7
        self::assertSame('█', $frame->characters[7]);
        self::assertSame('█', $frame->characters[14]);
    }

    /**
     * L153: DecrementInteger — loop runs exactly barWidth times.
     * Verify the last column of the bar area is rendered.
     */
    #[Test]
    public function lastBarColumnIsRendered(): void
    {
        $progressBar = ProgressBar::new(0.0)->withDisplay(0.0)->withPercentShown(false);

        $frame = new Frame(5, 1);
        $area = new Rect(0, 0, 5, 1);
        $progressBar->render($frame, $area, $this->context);

        // All 5 columns should be empty dashes
        self::assertSame('─', $frame->characters[4]);
    }

    /**
     * L168/L169: Ternary mutations — ensure the percent BOLD attribute is always set.
     */
    #[Test]
    public function percentTextIsBold(): void
    {
        $progressBar = ProgressBar::new(0.5)->withDisplay(0.5)->withPercentShown(true);

        $frame = new Frame(20, 1);
        $area = new Rect(0, 0, 20, 1);
        $progressBar->render($frame, $area, $this->context);

        $percentIndex = $this->findCharIndex($frame, '%', 20);
        self::assertNotNull($percentIndex);
        self::assertSame(Attr::BOLD, $frame->attributes[$percentIndex] & Attr::BOLD);
    }

    /**
     * L113: GreaterThan — $barArea->width > 0 ? localX / barArea->width : 0.0.
     * Click on a bar with label consuming all width (barWidth=0) should not crash.
     */
    #[Test]
    public function clickOnZeroWidthBarAreaReturnsUnchanged(): void
    {
        // Label "ABCDE" => labelWidth = 6, area width = 6 => barWidth = 0
        $progressBar = ProgressBar::new()
            ->withLabel('ABCDE')
            ->withProgress(0.0)
            ->withClickable();

        $area = new Rect(0, 0, 6, 1);
        // Click inside area but bar portion has zero width
        $click = new MouseMsg(0, 0, MouseAction::Press, MouseButton::Left);
        $result = $progressBar->update($click, $area);
        // Click is outside barRect (which has width=0), should return unchanged
        self::assertSame($progressBar, $result->model);
    }

    /**
     * Percentage text at 0%: should render "  0%".
     */
    #[Test]
    public function percentageTextAtZeroPercent(): void
    {
        $progressBar = ProgressBar::new(0.0)->withDisplay(0.0)->withPercentShown(true);

        $frame = new Frame(20, 1);
        $area = new Rect(0, 0, 20, 1);
        $progressBar->render($frame, $area, $this->context);

        $row = $this->extractRow($frame, 0, 20);
        self::assertStringContainsString('0%', $row);
    }

    /**
     * Percentage not rendered when showPercent is false (explicit).
     */
    #[Test]
    public function percentageNotRenderedWhenDisabled(): void
    {
        $progressBar = ProgressBar::new(0.5)->withDisplay(0.5)->withPercentShown(false);

        $frame = new Frame(20, 1);
        $area = new Rect(0, 0, 20, 1);
        $progressBar->render($frame, $area, $this->context);

        $row = $this->extractRow($frame, 0, 20);
        self::assertStringNotContainsString('%', $row);
    }

    /**
     * Verify different fractional block characters are used correctly.
     * display=0.1125 on width=8: fillFraction=0.9, fullCells=0, remainder=0.9,
     * fractionIndex=(int)round(0.9*8)=(int)round(7.2)=7 => BLOCKS[7]='▉'.
     */
    #[Test]
    public function fractionalBlockCharacterSelection(): void
    {
        // display = 1/8 = 0.125 on width=8: fillFraction=1.0, fullCells=1, remainder=0.0
        // That's exact, no fraction. Let's use display=0.0625 on width=8:
        // fillFraction=0.5, fullCells=0, remainder=0.5, fractionIndex=round(4.0)=4 => BLOCKS[4]='▌'
        $progressBar = ProgressBar::new(0.0625)->withDisplay(0.0625)->withPercentShown(false);

        $frame = new Frame(8, 1);
        $area = new Rect(0, 0, 8, 1);
        $progressBar->render($frame, $area, $this->context);

        self::assertSame('▌', $frame->characters[0]);
        self::assertSame('─', $frame->characters[1]);
    }

    /**
     * Click on a bar with a label — click position must account for label offset.
     */
    #[Test]
    public function clickOnLabeledBarAccountsForLabelOffset(): void
    {
        $progressBar = ProgressBar::new()
            ->withLabel('AB')
            ->withProgress(0.0)
            ->withClickable();

        $area = new Rect(0, 0, 24, 1);
        $click = new MouseMsg(13, 0, MouseAction::Press, MouseButton::Left);
        $result = $progressBar->update($click, $area);
        self::assertSame(0.5, $result->model->progress());
    }

    /**
     * Mouse move (not click) should not change progress.
     */
    #[Test]
    public function mouseMoveDoesNotChangeProgress(): void
    {
        $progressBar = ProgressBar::new()->withProgress(0.0)->withClickable();
        $area = new Rect(0, 0, 20, 1);
        $mouseMove = new MouseMsg(10, 0, MouseAction::Motion, MouseButton::None);

        $result = $progressBar->update($mouseMove, $area);
        self::assertSame($progressBar, $result->model);
    }

    /**
     * Spring factor affects animation speed — display after one tick
     * should be exactly springFactor * difference.
     */
    #[Test]
    public function aReplacedSpringFactorSetsTheAnimationStep(): void
    {
        $area = Rect::fromSize(20, 1);
        $progressBar = ProgressBar::new()->withDisplay(0.0)->withProgress(1.0)->withSpringFactor(0.5);

        $result = $progressBar->update(new TickMsg(0.0), $area);
        self::assertSame(0.5, $result->model->displayValue(), 'One tick covers half the remaining distance');

        $second = $result->model->update(new TickMsg(1 / 60), $area);
        self::assertSame(0.75, $second->model->displayValue(), 'Then half of what is left');
    }

    #[Test]
    public function springFactorAffectsAnimationSpeed(): void
    {
        $progressBar = ProgressBar::new()->withDisplay(0.0)->withProgress(1.0);
        // default springFactor = 0.15, so one tick covers 0.0 + (1.0 - 0.0) * 0.15
        $area = Rect::fromSize(20, 1);
        $result = $progressBar->update(new TickMsg(0.0), $area);
        self::assertEqualsWithDelta(0.15, $result->model->displayValue(), 1e-12);
    }

    /**
     * L165: RoundingFamily — ceil mutant: display=0.339 => ceil(33.9)=34 same as round.
     * Use display=0.334 => round(33.4)=33, ceil(33.4)=34. Must show "33%".
     */
    #[Test]
    public function percentageTextUsesRoundNotCeil(): void
    {
        $progressBar = ProgressBar::new(0.334)->withDisplay(0.334)->withPercentShown(true);

        $frame = new Frame(20, 1);
        $area = new Rect(0, 0, 20, 1);
        $progressBar->render($frame, $area, $this->context);

        $row = $this->extractRow($frame, 0, 20);
        self::assertStringContainsString('33%', $row);
        self::assertStringNotContainsString('34%', $row);
    }

    /**
     * L169: GreaterThan mutant — $this->display > 0.5 changed to >= 0.5 for background.
     * At display=0.5, background should be baseBackground (not fillColor).
     */
    #[Test]
    public function percentBackgroundAtExactlyHalfUsesBaseBackground(): void
    {
        $theme = Theme::dark();
        $fillColor = $theme->fillStyle->foregroundValue();
        $baseBackground = $theme->base->backgroundValue();

        $progressBar = ProgressBar::new(0.5)->withDisplay(0.5)->withPercentShown(true);

        $frame = new Frame(20, 1);
        $area = new Rect(0, 0, 20, 1);
        $progressBar->render($frame, $area, $this->context);

        $percentIndex = $this->findCharIndex($frame, '%', 20);
        self::assertNotNull($percentIndex);
        self::assertSame($baseBackground, $frame->backgrounds[$percentIndex]);
        self::assertNotSame($fillColor, $frame->backgrounds[$percentIndex]);
    }

    /**
     * L181: DecrementInteger/IncrementInteger — max(0, ...) -> max(1, ...).
     * When label consumes all width, barWidth should be exactly 0, causing early return.
     * With max(1,...), barWidth would be 1 and a bar cell would incorrectly render.
     */
    #[Test]
    public function barWidthIsZeroWhenLabelConsumesAllWidth(): void
    {
        // Label "ABC" => labelWidth = 3 + 1 = 4, area width = 4 => barWidth = max(0, 4-4) = 0
        // With max(1,...), barWidth = 1 and a bar block would render at column 4
        $progressBar = ProgressBar::new()
            ->withLabel('ABC')
            ->withProgress(1.0)
            ->withPercentShown(false);

        $frame = new Frame(10, 1);
        $area = new Rect(0, 0, 4, 1);
        $progressBar->render($frame, $area, $this->context);

        // barWidth=0 triggers early return, nothing renders (not even label)
        self::assertSame(' ', $frame->characters[0]);
        // If max(0,...) mutated to max(1,...), barWidth=1, early return is skipped,
        // and a full block would appear at barArea.x = 0+4 = 4
        self::assertNotSame('█', $frame->characters[4]);
    }

    #[Test]
    public function labelWidthUsesTerminalColumnsNotCodepointOrByteCount(): void
    {
        // "日" occupies two terminal columns, followed by one separator column.
        $progressBar = ProgressBar::new()
            ->withLabel("\u{65E5}")
            ->withProgress(1.0)
            ->withPercentShown(false);

        $frame = new Frame(12, 1);
        $area = new Rect(0, 0, 12, 1);
        $progressBar->render($frame, $area, $this->context);

        self::assertSame('', $frame->characters[1], 'The CJK continuation cell belongs to the label');
        self::assertSame('█', $frame->characters[3]);
        $fullBlocks = 0;
        for ($column = 3; $column < 12; ++$column) {
            if ($frame->characters[$column] === '█') {
                ++$fullBlocks;
            }
        }
        self::assertSame(9, $fullBlocks);
    }

    /**
     * L98: LessThan — abs($difference) < 0.001 vs <= 0.001.
     * At exactly 0.001 difference, < returns false (should animate), <= would return true (would snap).
     * Verify the spring factor is applied, not snapped.
     */
    #[Test]
    public function tickAnimatesWhenDifferenceIsExactlyThreshold(): void
    {
        $progressBar = ProgressBar::new(0.501)->withDisplay(0.500)->withSpringFactor(0.15);
        $area = Rect::fromSize(20, 1);
        $result = $progressBar->update(new TickMsg(0.0), $area);

        // With < 0.001: abs(0.001) < 0.001 is false => spring applies: 0.500 + 0.001*0.15 = 0.50015
        // With <= 0.001: abs(0.001) <= 0.001 is true => snaps to 0.501
        $display = $result->model->displayValue();
        self::assertNotSame(0.501, $display, 'Should animate, not snap to target');
        self::assertGreaterThan(0.500, $display);
        self::assertLessThan(0.501, $display);
    }

    /**
     * L122: LessThanOrEqualTo — width=0 must early return, rendering nothing.
     * If mutated to < 0, width=0 would proceed to render (and the buffer would be modified).
     */
    #[Test]
    public function renderWithWidthZeroDoesNotModifyBuffer(): void
    {
        $progressBar = ProgressBar::new(1.0)->withDisplay(1.0)->withPercentShown(false);
        $frame = new Frame(5, 1);
        $area = new Rect(0, 0, 0, 1);
        $progressBar->render($frame, $area, $this->context);

        // Buffer should remain entirely untouched
        for ($column = 0; $column < 5; ++$column) {
            self::assertSame(' ', $frame->characters[$column], "Column $column should remain blank with width=0");
            self::assertSame(0, $frame->foregrounds[$column]);
        }
    }

    /**
     * L151: DecrementInteger — $remainder * 8 vs $remainder * 7.
     * With remainder=0.6: round(0.6*8)=round(4.8)=5, round(0.6*7)=round(4.2)=4. Different blocks.
     */
    #[Test]
    public function fractionIndexUsesMultiplierOf8Not7(): void
    {
        // display=0.36 on width=10: fillFraction=3.6, fullCells=3, remainder=0.6
        // round(0.6 * 8) = round(4.8) = 5 => BLOCKS[5] = '▋'
        // round(0.6 * 7) = round(4.2) = 4 => BLOCKS[4] = '▌'
        $progressBar = ProgressBar::new(0.36)->withDisplay(0.36)->withPercentShown(false);

        $frame = new Frame(10, 1);
        $area = new Rect(0, 0, 10, 1);
        $progressBar->render($frame, $area, $this->context);

        self::assertSame('▋', $frame->characters[3]);
    }

    /**
     * L151: RoundingFamily — round vs floor.
     * remainder=0.1: round(0.8)=1, floor(0.8)=0. Round gives '▏', floor gives ' '.
     */
    #[Test]
    public function fractionIndexUsesRoundNotFloor(): void
    {
        // display=0.31 on width=10: fillFraction=3.1, fullCells=3, remainder=0.1
        // round(0.1 * 8) = round(0.8) = 1 => BLOCKS[1] = '▏'
        // floor(0.1 * 8) = floor(0.8) = 0 => BLOCKS[0] = ' ' (or no fractional block)
        $progressBar = ProgressBar::new(0.31)->withDisplay(0.31)->withPercentShown(false);

        $frame = new Frame(10, 1);
        $area = new Rect(0, 0, 10, 1);
        $progressBar->render($frame, $area, $this->context);

        self::assertSame('▏', $frame->characters[3]);
    }

    /**
     * L151: RoundingFamily — round vs ceil.
     * remainder=0.3: round(2.4)=2, ceil(2.4)=3. Round gives '▎', ceil gives '▍'.
     */
    #[Test]
    public function fractionIndexUsesRoundNotCeil(): void
    {
        // display=0.33 on width=10: fillFraction=3.3, fullCells=3, remainder=0.3
        // round(0.3 * 8) = round(2.4) = 2 => BLOCKS[2] = '▎'
        // ceil(0.3 * 8) = ceil(2.4) = 3 => BLOCKS[3] = '▍'
        $progressBar = ProgressBar::new(0.33)->withDisplay(0.33)->withPercentShown(false);

        $frame = new Frame(10, 1);
        $area = new Rect(0, 0, 10, 1);
        $progressBar->render($frame, $area, $this->context);

        self::assertSame('▎', $frame->characters[3]);
    }

    /**
     * L153: LessThan — for loop runs exactly barWidth times.
     * If mutated to <=, one extra column would be written past the bar area.
     */
    #[Test]
    public function barLoopRendersExactlyBarWidthCells(): void
    {
        $progressBar = ProgressBar::new(1.0)->withDisplay(1.0)->withPercentShown(false);

        $frame = new Frame(8, 1);
        $area = new Rect(0, 0, 5, 1);
        $progressBar->render($frame, $area, $this->context);

        // Columns 0-4 should be full blocks
        for ($column = 0; $column < 5; ++$column) {
            self::assertSame('█', $frame->characters[$column]);
        }
        // Column 5 must remain blank (not written by the loop)
        self::assertSame(' ', $frame->characters[5]);
    }

    #[Test]
    public function fillRectUsesHeightOneNotTwo(): void
    {
        // L157, L165 IncrementInteger: fillRect height 1 → 2. Second row must remain untouched.
        $frame = new Frame(10, 2);
        for ($i = 0; $i < 20; ++$i) {
            $frame->putChar($i % 10, intdiv($i, 10), '.');
        }
        // Render uses $display (not $progress); set it directly so the bar actually fills.
        $bar = ProgressBar::new()->withProgress(0.5)->withDisplay(0.5);

        $bar->render($frame, new Rect(0, 0, 10, 1), new RenderContext(Theme::dark()));

        for ($x = 0; $x < 10; ++$x) {
            self::assertSame('.', $frame->characters[10 + $x], "Row 1 col {$x} must remain untouched (height must be 1)");
        }
    }

    #[Test]
    public function fillRectWritesNoTextAttributes(): void
    {
        // L157 DecrementInteger/IncrementInteger: attributes parameter for fillRect must be 0.
        // Disable percent text so its BOLD attribute doesn't pollute the bar cells.
        $frame = new Frame(10, 1);
        $bar = ProgressBar::new()
            ->withProgress(0.5)
            ->withDisplay(0.5)
            ->withPercentShown(false);

        $bar->render($frame, new Rect(0, 0, 10, 1), new RenderContext(Theme::dark()));

        for ($x = 0; $x < 10; ++$x) {
            self::assertSame(0, $frame->attributes[$x], "Col {$x} must have no text attributes");
        }
    }

    #[Test]
    public function emptyFillWidthEqualsBarWidthMinusPartialColumn(): void
    {
        // L165 Minus → Plus: width parameter must be (barWidth - partialColumn), not (barWidth + partialColumn).
        // With Plus mutation, the fill extends past the bar's right edge.
        $frame = new Frame(15, 1);
        for ($x = 0; $x < 15; ++$x) {
            $frame->putChar($x, 0, '.');
        }
        // 10-wide bar at x=0 with 30% fill → fullCells=3 → empty fill spans cols 3..9 (width 7)
        $bar = ProgressBar::new()
            ->withProgress(0.3)
            ->withDisplay(0.3)
            ->withPercentShown(false);

        $bar->render($frame, new Rect(0, 0, 10, 1), new RenderContext(Theme::dark()));

        // Cells beyond the bar (cols 10..14) must remain untouched
        for ($x = 10; $x < 15; ++$x) {
            self::assertSame('.', $frame->characters[$x], "Col {$x} (beyond bar) must remain untouched — empty-fill width must clamp to barWidth - partialColumn");
        }
    }

    protected function setUp(): void
    {
        $this->context = new RenderContext(Theme::dark());
    }

    private function extractRow(Frame $frame, int $startColumn, int $width): string
    {
        $row = '';
        for ($column = $startColumn; $column < $startColumn + $width; ++$column) {
            $row .= $frame->characters[$column];
        }

        return $row;
    }

    private function findCharIndex(Frame $frame, string $needle, int $width): ?int
    {
        for ($column = 0; $column < $width; ++$column) {
            if ($frame->characters[$column] === $needle) {
                return $column;
            }
        }

        return null;
    }
}
