<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Layout;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\VerticalStack;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Widget\MeasureHeight;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

final class VerticalStackTest extends TestCase
{
    #[Test]
    public function reusesHeightsForTheSameImmutableOwnerAndViewport(): void
    {
        $calls = new VerticalStackCalls();
        $child = new CountingStackWidget(Constraint::exact(1), $calls);
        $owner = new stdClass();

        self::assertSame([1], VerticalStack::heightsFor($owner, [$child], 80, 24));
        self::assertSame([1], VerticalStack::heightsFor($owner, [$child], 80, 24));
        self::assertSame(1, $calls->constraints);
    }

    #[Test]
    public function viewportChangeInvalidatesTheCachedHeights(): void
    {
        $calls = new VerticalStackCalls();
        $child = new CountingStackWidget(Constraint::flex(), $calls);
        $owner = new stdClass();

        self::assertSame([24], VerticalStack::heightsFor($owner, [$child], 80, 24));
        self::assertSame([24], VerticalStack::heightsFor($owner, [$child], 81, 24));
        self::assertSame([25], VerticalStack::heightsFor($owner, [$child], 81, 25));
        self::assertSame(3, $calls->constraints);
    }

    #[Test]
    public function aNewImmutableOwnerGetsItsOwnMeasurement(): void
    {
        $calls = new VerticalStackCalls();
        $child = new CountingStackWidget(Constraint::exact(1), $calls);

        VerticalStack::heightsFor(new stdClass(), [$child], 80, 24);
        VerticalStack::heightsFor(new stdClass(), [$child], 80, 24);

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

        self::assertSame(
            [2, 3, 2, 4],
            VerticalStack::heightsFor(new stdClass(), $children, 80, 11),
        );
    }

    #[Test]
    public function assignsOneRowWhenNoSpaceRemainsForFlexChildren(): void
    {
        $calls = new VerticalStackCalls();
        $children = [
            new CountingStackWidget(Constraint::exact(4), $calls),
            new CountingStackWidget(Constraint::flex(0), $calls),
            new CountingStackWidget(Constraint::flex(), $calls),
        ];

        self::assertSame(
            [4, 1, 1],
            VerticalStack::heightsFor(new stdClass(), $children, 80, 4),
        );
    }

    #[Test]
    public function givesDivisionRemainderToTheLastFlexChild(): void
    {
        $calls = new VerticalStackCalls();
        $children = [
            new CountingStackWidget(Constraint::flex(), $calls),
            new CountingStackWidget(Constraint::flex(), $calls),
        ];

        self::assertSame(
            [2, 3],
            VerticalStack::heightsFor(new stdClass(), $children, 80, 5),
        );
    }

    #[Test]
    public function widthAwareMeasurementCanExpandANonExactChild(): void
    {
        $calls = new VerticalStackCalls();
        $child = new MeasuredStackWidget(Constraint::min(1), 4, $calls);
        $owner = new stdClass();

        self::assertSame([4], VerticalStack::heightsFor($owner, [$child], 20, 1));
        self::assertSame([4], VerticalStack::heightsFor($owner, [$child], 20, 1));
        self::assertSame(2, $calls->measurements);
    }

    #[Test]
    public function exactChildrenAndZeroWidthSkipHeightMeasurement(): void
    {
        $calls = new VerticalStackCalls();
        $exact = new MeasuredStackWidget(Constraint::exact(1), 4, $calls);
        $flex = new MeasuredStackWidget(Constraint::flex(), 4, $calls);

        self::assertSame(
            [1, 1],
            VerticalStack::heightsFor(new stdClass(), [$exact, $flex], 0, 2),
        );
        self::assertSame(0, $calls->measurements);

        self::assertSame(
            [1, 4],
            VerticalStack::heightsFor(new stdClass(), [$exact, $flex], 20, 2),
        );
        self::assertSame(2, $calls->measurements);
    }

    #[Test]
    public function measurementNeverShrinksTheConstraintHeight(): void
    {
        $calls = new VerticalStackCalls();
        $child = new MeasuredStackWidget(Constraint::min(3), 1, $calls);

        self::assertSame(
            [3],
            VerticalStack::heightsFor(new stdClass(), [$child], 20, 3),
        );
    }

    #[Test]
    public function emptyStackHasNoHeights(): void
    {
        self::assertSame([], VerticalStack::heightsFor(new stdClass(), [], 80, 24));
    }

    #[Test]
    public function negativeAvailableWidthIsClampedToZero(): void
    {
        $calls = new VerticalStackCalls();
        $child = new CountingStackWidget(Constraint::exact(1), $calls);

        $layout = VerticalStack::layoutFor(new stdClass(), [$child], -5, 5);

        self::assertSame([1], $layout->heights);
        self::assertFalse($layout->hasScrollbar);
        self::assertSame(0, $layout->childWidth);
    }

    #[Test]
    public function negativeAvailableHeightIsClampedToZero(): void
    {
        $layout = VerticalStack::layoutFor(new stdClass(), [], 10, -4);

        self::assertSame([], $layout->heights);
        self::assertFalse($layout->hasScrollbar);
        self::assertSame(10, $layout->childWidth);
    }

    #[Test]
    public function zeroHeightViewportScrollsAnyFixedContent(): void
    {
        $calls = new VerticalStackCalls();
        $child = new CountingStackWidget(Constraint::exact(1), $calls);

        $layout = VerticalStack::layoutFor(new stdClass(), [$child], 10, 0);

        self::assertTrue($layout->hasScrollbar);
        self::assertSame(9, $layout->childWidth);
    }

    #[Test]
    public function scrollbarNeverPushesTheChildWidthBelowZero(): void
    {
        $calls = new VerticalStackCalls();
        $child = new CountingStackWidget(Constraint::exact(3), $calls);

        $oneColumn = VerticalStack::layoutFor(new stdClass(), [$child], 1, 1);
        $zeroColumns = VerticalStack::layoutFor(new stdClass(), [$child], 0, 1);

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

        $layout = VerticalStack::layoutFor(new stdClass(), [$child], 5, 2);

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

        self::assertSame(
            [1, 1],
            VerticalStack::heightsFor(new stdClass(), $children, 80, 1),
        );
    }

    #[Test]
    public function aChildWithAFloorAndAFlexWeightGrowsPastItsFloor(): void
    {
        $calls = new VerticalStackCalls();
        $children = [
            new CountingStackWidget(Constraint::min(3)->withFlex(), $calls),
            new CountingStackWidget(Constraint::exact(2), $calls),
        ];

        self::assertSame(
            [8, 2],
            VerticalStack::heightsFor(new stdClass(), $children, 80, 10),
        );
    }

    #[Test]
    public function aFloorAndFlexChildNeverShrinksBelowItsFloor(): void
    {
        $calls = new VerticalStackCalls();
        $children = [
            new CountingStackWidget(Constraint::min(6)->withFlex(), $calls),
            new CountingStackWidget(Constraint::exact(2), $calls),
        ];

        self::assertSame(
            [6, 2],
            VerticalStack::heightsFor(new stdClass(), $children, 80, 4),
        );
    }

    #[Test]
    public function floorsShareTheRemainderInProportionToTheirWeights(): void
    {
        $calls = new VerticalStackCalls();
        $children = [
            new CountingStackWidget(Constraint::min(2)->withFlex(3), $calls),
            new CountingStackWidget(Constraint::min(2)->withFlex(1), $calls),
        ];

        self::assertSame(
            [8, 4],
            VerticalStack::heightsFor(new stdClass(), $children, 80, 12),
        );
    }

    #[Test]
    public function theRoundingRemainderGoesToTheLastFlooredChildSoTheStackFillsExactly(): void
    {
        $calls = new VerticalStackCalls();
        $children = [
            new CountingStackWidget(Constraint::min(1)->withFlex(), $calls),
            new CountingStackWidget(Constraint::min(1)->withFlex(), $calls),
        ];

        $heights = VerticalStack::heightsFor(new stdClass(), $children, 80, 9);

        self::assertSame([4, 5], $heights);
        self::assertSame(9, array_sum($heights), 'The stack leaves no row unused');
    }

    #[Test]
    public function aMeasuredChildTallerThanItsMinimumDoesNotPushTheStackOverTheViewport(): void
    {
        $calls = new VerticalStackCalls();
        $children = [
            new MeasuredStackWidget(Constraint::min(2), 6, $calls),
            new CountingStackWidget(Constraint::flex(), $calls),
        ];

        $layout = VerticalStack::layoutFor(new stdClass(), $children, 40, 23);

        self::assertSame([6, 17], $layout->heights);
        self::assertSame(23, $layout->totalHeight, 'Content that fits must not report an overflow');
        self::assertFalse($layout->hasScrollbar);
    }

    #[Test]
    public function aMeasuredFlexChildStillFillsTheSpaceLeftOver(): void
    {
        $calls = new VerticalStackCalls();
        $children = [
            new MeasuredStackWidget(Constraint::min(2), 6, $calls),
            new MeasuredStackWidget(Constraint::flex(), 3, $calls),
        ];

        $layout = VerticalStack::layoutFor(new stdClass(), $children, 40, 23);

        self::assertSame([6, 17], $layout->heights, 'Its measured height is a floor, not a cap');
        self::assertFalse($layout->hasScrollbar);
    }

    #[Test]
    public function aStackOfMeasuredChildrenTallerThanThePaneStillOverflows(): void
    {
        $calls = new VerticalStackCalls();
        $children = [
            new MeasuredStackWidget(Constraint::min(2), 15, $calls),
            new MeasuredStackWidget(Constraint::min(2), 15, $calls),
        ];

        $layout = VerticalStack::layoutFor(new stdClass(), $children, 40, 23);

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

        self::assertSame(
            [3, 17],
            VerticalStack::heightsFor(new stdClass(), $children, 80, 20),
        );
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

        $heights = VerticalStack::heightsFor(new stdClass(), $children, 80, 15);

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

        self::assertSame(
            [2, 2],
            VerticalStack::heightsFor(new stdClass(), $children, 80, 4),
        );
    }

    #[Test]
    public function aWeightedFlexChildBeforeAnUnweightedOneGetsItsFullShare(): void
    {
        $calls = new VerticalStackCalls();
        $children = [
            new CountingStackWidget(Constraint::flex(2), $calls),
            new CountingStackWidget(Constraint::flex(), $calls),
        ];

        self::assertSame(
            [4, 2],
            VerticalStack::heightsFor(new stdClass(), $children, 80, 6),
        );
    }

    #[Test]
    public function scrollbarWidthIsAppliedBeforeWidthAwareChildrenAreFinalized(): void
    {
        $calls = new VerticalStackCalls();
        $children = [
            new CountingStackWidget(Constraint::exact(2), $calls),
            new WidthMeasuredStackWidget($calls),
        ];

        $layout = VerticalStack::layoutFor(new stdClass(), $children, 8, 2);

        self::assertTrue($layout->hasScrollbar);
        self::assertSame(7, $layout->childWidth);
        self::assertSame([2, 2], $layout->heights);
        self::assertSame(4, $layout->totalHeight);
        self::assertSame([8, 7], $calls->measuredWidths);
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

    public function constraint(): Constraint
    {
        ++$this->calls->constraints;

        return $this->layoutConstraint;
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

    public function constraint(): Constraint
    {
        ++$this->calls->constraints;

        return $this->layoutConstraint;
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

    public function constraint(): Constraint
    {
        ++$this->calls->constraints;

        return Constraint::min(1);
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
