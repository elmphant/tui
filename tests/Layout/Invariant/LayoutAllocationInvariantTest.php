<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Layout\Invariant;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Direction;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Widget\Layout\Layout;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LayoutAllocationInvariantTest extends TestCase
{
    use RectInvariants;

    #[Test]
    #[DataProviderExternal(ConstraintSweep::class, 'cases')]
    public function everyChildOfARowStaysInsideTheArea(array $constraints, int $extent): void
    {
        $area = new Rect(4, 2, $extent, 5);

        self::assertAllWithin($this->rowRects($constraints, $area), $area);
    }

    #[Test]
    #[DataProviderExternal(ConstraintSweep::class, 'cases')]
    public function everyChildOfAColumnStaysInsideTheArea(array $constraints, int $extent): void
    {
        $area = new Rect(4, 2, 5, $extent);

        self::assertAllWithin($this->columnRects($constraints, $area), $area);
    }

    #[Test]
    #[DataProviderExternal(ConstraintSweep::class, 'cases')]
    public function siblingsNeverOverlap(array $constraints, int $extent): void
    {
        $area = new Rect(4, 2, $extent, 5);

        self::assertNoneOverlap($this->rowRects($constraints, $area));
    }

    #[Test]
    #[DataProviderExternal(ConstraintSweep::class, 'cases')]
    public function eachChildStartsWhereThePreviousOneEnds(array $constraints, int $extent): void
    {
        $area = new Rect(4, 2, $extent, 5);

        self::assertAdjacentAlong($this->rowRects($constraints, $area), $area, Direction::Horizontal);
    }

    #[Test]
    #[DataProviderExternal(ConstraintSweep::class, 'casesWithAnUncappedWeightedChild')]
    public function aWeightedChildWithoutACeilingLeavesNoColumnUnused(array $constraints, int $extent): void
    {
        $area = new Rect(4, 2, $extent, 5);
        $widths = self::extentsAlong($this->rowRects($constraints, $area), Direction::Horizontal);

        self::assertSame($extent, array_sum($widths));
    }

    #[Test]
    #[DataProviderExternal(ConstraintSweep::class, 'casesWhereEveryFloorFits')]
    public function eachChildLandsBetweenItsFloorAndItsCeiling(array $constraints, int $extent): void
    {
        $area = new Rect(4, 2, $extent, 5);
        $widths = self::extentsAlong($this->rowRects($constraints, $area), Direction::Horizontal);

        foreach ($constraints as $index => $constraint) {
            self::assertGreaterThanOrEqual($constraint->min, $widths[$index]);
            self::assertLessThanOrEqual($constraint->max, $widths[$index]);
        }
    }

    #[Test]
    #[DataProviderExternal(ConstraintSweep::class, 'cases')]
    public function theSameRowResolvesTheSameWayTwice(array $constraints, int $extent): void
    {
        $area = new Rect(4, 2, $extent, 5);

        self::assertEquals($this->rowRects($constraints, $area), $this->rowRects($constraints, $area));
    }

    #[Test]
    #[DataProviderExternal(ConstraintSweep::class, 'cases')]
    public function aColumnSizesItsChildrenLikeARowOfTheSameConstraints(array $constraints, int $extent): void
    {
        $row = new Rect(0, 0, $extent, 5);
        $column = new Rect(0, 0, 5, $extent);

        self::assertSame(
            self::extentsAlong($this->rowRects($constraints, $row), Direction::Horizontal),
            self::extentsAlong($this->columnRects($constraints, $column), Direction::Vertical),
        );
    }

    #[Test]
    public function everySweptRowAllocatesTheWidthsRecordedForIt(): void
    {
        $allocated = [];

        foreach (ConstraintSweep::cases() as $name => [$constraints, $extent]) {
            $allocated[$name] = Layout::row(...self::widgetsFor($constraints))->sizesFor($extent);
        }

        self::assertSame(AllocationBaseline::layoutRows(), $allocated);
    }

    /**
     * @param list<Constraint> $constraints
     *
     * @return list<Rect>
     */
    private function rowRects(array $constraints, Rect $area): array
    {
        return self::rectsOf(Layout::row(...self::widgetsFor($constraints))->computeChildRects($area));
    }

    /**
     * @param list<Constraint> $constraints
     *
     * @return list<Rect>
     */
    private function columnRects(array $constraints, Rect $area): array
    {
        return self::rectsOf(Layout::column(...self::widgetsFor($constraints))->computeChildRects($area));
    }

    /**
     * @param list<Constraint> $constraints
     *
     * @return list<ConstrainedWidget>
     */
    private static function widgetsFor(array $constraints): array
    {
        return array_map(static fn (Constraint $constraint): ConstrainedWidget => new ConstrainedWidget($constraint), $constraints);
    }
}
