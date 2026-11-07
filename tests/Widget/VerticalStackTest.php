<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Support\KeyedCache;
use Elmphant\Tui\Widget\Layout\VerticalStack;
use Elmphant\Tui\Widget\Layout\VerticalStackLayout;
use Elmphant\Tui\Widget\MeasureHeight;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class VerticalStackTest extends TestCase
{
    #[Test]
    public function reusesHeightsForTheSameCacheAndViewport(): void
    {
        $calls = new VerticalStackCalls();
        $child = new CountingStackWidget(Constraint::exact(1), $calls);
        $memo = new KeyedCache();

        self::assertSame([1], VerticalStack::layoutUsing($memo, [$child], 80, 24)->heights);
        self::assertSame([1], VerticalStack::layoutUsing($memo, [$child], 80, 24)->heights);
        self::assertSame(1, $calls->constraints);
    }

    #[Test]
    public function viewportChangeInvalidatesTheCachedHeights(): void
    {
        $calls = new VerticalStackCalls();
        $child = new CountingStackWidget(Constraint::flex(), $calls);
        $memo = new KeyedCache();

        self::assertSame([24], VerticalStack::layoutUsing($memo, [$child], 80, 24)->heights);
        self::assertSame([24], VerticalStack::layoutUsing($memo, [$child], 81, 24)->heights);
        self::assertSame([25], VerticalStack::layoutUsing($memo, [$child], 81, 25)->heights);
        self::assertSame(3, $calls->constraints);
    }

    #[Test]
    public function aViewportWhoseDigitsRunTogetherIsStillADifferentViewport(): void
    {
        $child = new CountingStackWidget(Constraint::flex(), new VerticalStackCalls());
        $memo = new KeyedCache();

        self::assertSame([23], VerticalStack::layoutUsing($memo, [$child], 1, 23)->heights);
        self::assertSame([3], VerticalStack::layoutUsing($memo, [$child], 12, 3)->heights);
    }

    #[Test]
    public function aChildWithACeilingOfNothingIsGivenNoRow(): void
    {
        $child = new CountingStackWidget(Constraint::max(0), new VerticalStackCalls());

        self::assertSame([0], $this->heightsOf([$child], 80, 5));
    }

    #[Test]
    public function aFreshCacheMeasuresTheStackAgain(): void
    {
        $calls = new VerticalStackCalls();
        $child = new CountingStackWidget(Constraint::exact(1), $calls);

        $this->heightsOf([$child], 80, 24);
        $this->heightsOf([$child], 80, 24);

        self::assertSame(2, $calls->constraints);
    }

    #[Test]
    public function distributesFlexSpaceAfterFixedAndMinimumChildren(): void
    {
        $calls = new VerticalStackCalls();
        $children = [
            new CountingStackWidget(Constraint::exact(2), $calls),
            new CountingStackWidget(Constraint::min(3), $calls),
            new CountingStackWidget(Constraint::flex(), $calls),
            new CountingStackWidget(Constraint::flex(2), $calls),
        ];

        self::assertSame([2, 3, 2, 4], $this->heightsOf($children, 80, 11));
    }

    #[Test]
    public function aDeclaredStartingSizeIsServedBeforeTheSharingOut(): void
    {
        $calls = new VerticalStackCalls();
        $children = [
            new CountingStackWidget(Constraint::flex()->withBasis(10), $calls),
            new CountingStackWidget(Constraint::flex(), $calls),
        ];

        self::assertSame([17, 7], $this->heightsOf($children, 80, 24));
    }

    #[Test]
    public function assignsOneRowWhenNoSpaceRemainsForFlexChildren(): void
    {
        $calls = new VerticalStackCalls();
        $children = [
            new CountingStackWidget(Constraint::exact(4), $calls),
            new CountingStackWidget(Constraint::flex(), $calls),
            new CountingStackWidget(Constraint::flex(), $calls),
        ];

        self::assertSame([4, 1, 1], $this->heightsOf($children, 80, 4));
    }

    #[Test]
    public function givesDivisionRemainderToTheLastFlexChild(): void
    {
        $calls = new VerticalStackCalls();
        $children = [
            new CountingStackWidget(Constraint::flex(), $calls),
            new CountingStackWidget(Constraint::flex(), $calls),
        ];

        self::assertSame([2, 3], $this->heightsOf($children, 80, 5));
    }

    #[Test]
    public function widthAwareMeasurementCanExpandANonExactChild(): void
    {
        $calls = new VerticalStackCalls();
        $child = new MeasuredStackWidget(Constraint::content()->withMin(1), 4, $calls);
        $memo = new KeyedCache();

        self::assertSame([4], VerticalStack::layoutUsing($memo, [$child], 20, 1)->heights);
        self::assertSame([4], VerticalStack::layoutUsing($memo, [$child], 20, 1)->heights);
        self::assertSame(2, $calls->measurements);
    }

    #[Test]
    public function exactChildrenAndZeroWidthSkipHeightMeasurement(): void
    {
        $calls = new VerticalStackCalls();
        $exact = new MeasuredStackWidget(Constraint::exact(1), 4, $calls);
        $flex = new MeasuredStackWidget(Constraint::content()->withFlex(), 4, $calls);

        self::assertSame([1, 1], $this->heightsOf([$exact, $flex], 0, 2));
        self::assertSame(0, $calls->measurements);

        self::assertSame([1, 4], $this->heightsOf([$exact, $flex], 20, 2));
        self::assertSame(2, $calls->measurements);
    }

    #[Test]
    public function measurementNeverShrinksTheConstraintHeight(): void
    {
        $calls = new VerticalStackCalls();
        $child = new MeasuredStackWidget(Constraint::content()->withMin(3), 1, $calls);

        self::assertSame([3], $this->heightsOf([$child], 20, 3));
    }

    #[Test]
    public function emptyStackHasNoHeights(): void
    {
        self::assertSame([], $this->heightsOf([], 80, 24));
    }

    #[Test]
    public function negativeAvailableWidthIsClampedToZero(): void
    {
        $calls = new VerticalStackCalls();
        $child = new CountingStackWidget(Constraint::exact(1), $calls);

        $layout = $this->layoutOf([$child], -5, 5);

        self::assertSame([1], $layout->heights);
        self::assertFalse($layout->hasScrollbar);
        self::assertSame(0, $layout->childWidth);
    }

    #[Test]
    public function negativeAvailableHeightIsClampedToZero(): void
    {
        $layout = $this->layoutOf([], 10, -4);

        self::assertSame([], $layout->heights);
        self::assertFalse($layout->hasScrollbar);
        self::assertSame(10, $layout->childWidth);
    }

    #[Test]
    public function zeroHeightViewportScrollsAnyFixedContent(): void
    {
        $calls = new VerticalStackCalls();
        $child = new CountingStackWidget(Constraint::exact(1), $calls);

        $layout = $this->layoutOf([$child], 10, 0);

        self::assertTrue($layout->hasScrollbar);
        self::assertSame(9, $layout->childWidth);
    }

    #[Test]
    public function scrollbarNeverPushesTheChildWidthBelowZero(): void
    {
        $calls = new VerticalStackCalls();
        $child = new CountingStackWidget(Constraint::exact(3), $calls);

        $oneColumn = $this->layoutOf([$child], 1, 1);
        $zeroColumns = $this->layoutOf([$child], 0, 1);

        self::assertTrue($oneColumn->hasScrollbar);
        self::assertSame(0, $oneColumn->childWidth);
        self::assertTrue($zeroColumns->hasScrollbar);
        self::assertSame(0, $zeroColumns->childWidth);
    }

    #[Test]
    public function aScrollbarWithoutWidthAwareChildrenResolvesOnlyOnce(): void
    {
        $calls = new VerticalStackCalls();
        $child = new CountingStackWidget(Constraint::min(3), $calls);

        $layout = $this->layoutOf([$child], 5, 2);

        self::assertSame([3], $layout->heights);
        self::assertTrue($layout->hasScrollbar);
        self::assertSame(4, $layout->childWidth);
        self::assertSame(1, $calls->constraints);
    }

    #[Test]
    public function flexChildrenKeepOneRowWhenSpaceIsSmallerThanTheirCount(): void
    {
        $calls = new VerticalStackCalls();
        $children = [
            new CountingStackWidget(Constraint::flex(), $calls),
            new CountingStackWidget(Constraint::flex(), $calls),
        ];

        self::assertSame([1, 1], $this->heightsOf($children, 80, 1));
    }

    #[Test]
    public function aChildWithAFloorAndAFlexWeightGrowsPastItsFloor(): void
    {
        $calls = new VerticalStackCalls();
        $children = [
            new CountingStackWidget(Constraint::min(3)->withFlex(), $calls),
            new CountingStackWidget(Constraint::exact(2), $calls),
        ];

        self::assertSame([8, 2], $this->heightsOf($children, 80, 10));
    }

    #[Test]
    public function aFloorAndFlexChildNeverShrinksBelowItsFloor(): void
    {
        $calls = new VerticalStackCalls();
        $children = [
            new CountingStackWidget(Constraint::min(6)->withFlex(), $calls),
            new CountingStackWidget(Constraint::exact(2), $calls),
        ];

        self::assertSame([6, 2], $this->heightsOf($children, 80, 4));
    }

    #[Test]
    public function floorsShareTheRemainderInProportionToTheirWeights(): void
    {
        $calls = new VerticalStackCalls();
        $children = [
            new CountingStackWidget(Constraint::min(2)->withFlex(3), $calls),
            new CountingStackWidget(Constraint::min(2)->withFlex(1), $calls),
        ];

        self::assertSame([8, 4], $this->heightsOf($children, 80, 12));
    }

    #[Test]
    public function theRoundingRemainderGoesToTheLastFlooredChildSoTheStackFillsExactly(): void
    {
        $calls = new VerticalStackCalls();
        $children = [
            new CountingStackWidget(Constraint::min(1)->withFlex(), $calls),
            new CountingStackWidget(Constraint::min(1)->withFlex(), $calls),
        ];

        $heights = $this->heightsOf($children, 80, 9);

        self::assertSame([4, 5], $heights);
        self::assertSame(9, array_sum($heights), 'The stack leaves no row unused');
    }

    #[Test]
    public function aMeasuredChildTallerThanItsMinimumDoesNotPushTheStackOverTheViewport(): void
    {
        $calls = new VerticalStackCalls();
        $children = [
            new MeasuredStackWidget(Constraint::content()->withMin(2), 6, $calls),
            new CountingStackWidget(Constraint::flex(), $calls),
        ];

        $layout = $this->layoutOf($children, 40, 23);

        self::assertSame([6, 17], $layout->heights);
        self::assertSame(23, $layout->totalHeight, 'Content that fits must not report an overflow');
        self::assertFalse($layout->hasScrollbar);
    }

    #[Test]
    public function aMeasuredFlexChildStillFillsTheSpaceLeftOver(): void
    {
        $calls = new VerticalStackCalls();
        $children = [
            new MeasuredStackWidget(Constraint::content()->withMin(2), 6, $calls),
            new MeasuredStackWidget(Constraint::content()->withFlex(), 3, $calls),
        ];

        $layout = $this->layoutOf($children, 40, 23);

        self::assertSame([6, 17], $layout->heights, 'Its measured height is a floor, not a cap');
        self::assertFalse($layout->hasScrollbar);
    }

    #[Test]
    public function aStackOfMeasuredChildrenTallerThanThePaneStillOverflows(): void
    {
        $calls = new VerticalStackCalls();
        $children = [
            new MeasuredStackWidget(Constraint::content()->withMin(2), 15, $calls),
            new MeasuredStackWidget(Constraint::content()->withMin(2), 15, $calls),
        ];

        $layout = $this->layoutOf($children, 40, 23);

        self::assertSame(30, $layout->totalHeight);
        self::assertTrue($layout->hasScrollbar, 'Real overflow must still be reported');
    }

    #[Test]
    public function aCeilingChildStopsGrowingAtItsCeiling(): void
    {
        $calls = new VerticalStackCalls();
        $children = [
            new CountingStackWidget(Constraint::max(3), $calls),
            new CountingStackWidget(Constraint::flex(), $calls),
        ];

        self::assertSame([3, 17], $this->heightsOf($children, 80, 20));
    }

    #[Test]
    public function aMeasurementNeverLiftsTheDeclaredCeiling(): void
    {
        $calls = new VerticalStackCalls();
        $children = [
            new MeasuredStackWidget(Constraint::content()->withMax(3), 9, $calls),
            new CountingStackWidget(Constraint::flex(), $calls),
        ];

        self::assertSame([3, 7], $this->heightsOf($children, 80, 10));
    }

    #[Test]
    public function everyFlooredChildKeepsItsOwnShareWhenThreeCompete(): void
    {
        $calls = new VerticalStackCalls();
        $children = [
            new CountingStackWidget(Constraint::min(2)->withFlex(), $calls),
            new CountingStackWidget(Constraint::min(2)->withFlex(), $calls),
            new CountingStackWidget(Constraint::min(2)->withFlex(), $calls),
        ];

        $heights = $this->heightsOf($children, 80, 15);

        self::assertSame([5, 5, 5], $heights);
        self::assertSame(15, array_sum($heights), 'The stack leaves no row unused');
    }

    #[Test]
    public function exactFlexDivisionLeavesTheLastChildUncorrected(): void
    {
        $calls = new VerticalStackCalls();
        $children = [
            new CountingStackWidget(Constraint::flex(), $calls),
            new CountingStackWidget(Constraint::flex(), $calls),
        ];

        self::assertSame([2, 2], $this->heightsOf($children, 80, 4));
    }

    #[Test]
    public function aWeightedFlexChildBeforeAnUnweightedOneGetsItsFullShare(): void
    {
        $calls = new VerticalStackCalls();
        $children = [
            new CountingStackWidget(Constraint::flex(2), $calls),
            new CountingStackWidget(Constraint::flex(), $calls),
        ];

        self::assertSame([4, 2], $this->heightsOf($children, 80, 6));
    }

    #[Test]
    public function scrollbarWidthIsAppliedBeforeWidthAwareChildrenAreFinalized(): void
    {
        $calls = new VerticalStackCalls();
        $children = [
            new CountingStackWidget(Constraint::exact(2), $calls),
            new WidthMeasuredStackWidget($calls),
        ];

        $layout = $this->layoutOf($children, 8, 2);

        self::assertTrue($layout->hasScrollbar);
        self::assertSame(7, $layout->childWidth);
        self::assertSame([2, 2], $layout->heights);
        self::assertSame(4, $layout->totalHeight);
        self::assertSame([8, 7], $calls->measuredWidths);
    }

    /**
     * @param list<Widget> $children
     *
     * @return list<int>
     */
    private function heightsOf(array $children, int $availableWidth, int $availableHeight): array
    {
        return $this->layoutOf($children, $availableWidth, $availableHeight)->heights;
    }

    /**
     * @param list<Widget> $children
     */
    private function layoutOf(array $children, int $availableWidth, int $availableHeight): VerticalStackLayout
    {
        return VerticalStack::layoutUsing(new KeyedCache(), $children, $availableWidth, $availableHeight);
    }
}

final class VerticalStackCalls
{
    public int $constraints = 0;
    public int $measurements = 0;
    /**
     * @var list<int>
     */
    public array $measuredWidths = [];
}

final readonly class CountingStackWidget implements Widget
{
    public function __construct(
        private Constraint $layoutConstraint,
        private VerticalStackCalls $calls,
    ) {
    }

    public function sizing(): Sizing
    {
        ++$this->calls->constraints;

        return Sizing::of($this->layoutConstraint, $this->layoutConstraint);
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
    }
}

final readonly class MeasuredStackWidget implements Widget, MeasureHeight
{
    public function __construct(
        private Constraint $layoutConstraint,
        private int $measuredHeight,
        private VerticalStackCalls $calls,
    ) {
    }

    public function sizing(): Sizing
    {
        ++$this->calls->constraints;

        return Sizing::of($this->layoutConstraint, $this->layoutConstraint);
    }

    public function measureHeight(int $width): int
    {
        ++$this->calls->measurements;

        return $this->measuredHeight;
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
    }
}

final readonly class WidthMeasuredStackWidget implements Widget, MeasureHeight
{
    public function __construct(
        private VerticalStackCalls $calls,
    ) {
    }

    public function sizing(): Sizing
    {
        ++$this->calls->constraints;

        return Sizing::of(Constraint::min(1), Constraint::content()->withMin(1));
    }

    public function measureHeight(int $width): int
    {
        ++$this->calls->measurements;
        $this->calls->measuredWidths[] = $width;

        return $width < 8 ? 2 : 1;
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
    }
}
