<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Closure;
use Elmphant\Tui\Layout\Axis\Justification;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Widget\Layout\CrossAlignment;
use Elmphant\Tui\Widget\Layout\Flex;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\MeasureHeight;
use Elmphant\Tui\Widget\Plan\WidgetTree;
use Elmphant\Tui\Widget\Prose\Spacer;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Structure\Box;
use Elmphant\Tui\Widget\Widget;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FlexTest extends TestCase
{
    #[Test]
    public function defaultsAreObservableAndZeroValuesAreValid(): void
    {
        $zeroBasis = Flex::row()
            ->add(Text::new('default'), Constraint::max(0)->withFlex(0))
            ->add(Text::new('fixed'), Constraint::max(1)->withFlex(0)->withBasis(1));
        self::assertSame([0, 1], $this->mainSizes($zeroBasis, Rect::fromSize(2, 1)));

        $defaultBasis = Flex::row()
            ->add(Text::new('default'), Constraint::min(0))
            ->add(Text::new('fixed'), Constraint::max(1)->withFlex(0)->withBasis(1));
        self::assertSame([0, 1], $this->mainSizes($defaultBasis, Rect::fromSize(2, 1)));

        $defaultGrow = Flex::row()
            ->add(Text::new('default'))
            ->add(Text::new('explicit'), Constraint::flex());
        self::assertSame([10, 3], $this->mainSizes($defaultGrow, Rect::fromSize(13, 1)));

        $defaultShrink = Flex::row()
            ->add(Text::new('default'), Constraint::flex()->withBasis(4))
            ->add(Text::new('explicit'), Constraint::flex()->withBasis(4)->withShrink(1));
        self::assertSame([3, 2], $this->mainSizes($defaultShrink, Rect::fromSize(5, 1)));

        $zeroGap = Flex::row()->withGap(0);
        self::assertNotSame(Flex::row(), $zeroGap);
        self::assertSame([], $zeroGap->computeChildRects(Rect::fromSize(1, 1)));

        $fixedAtZero = Flex::row()->add(Text::new('zero'), Constraint::min(0)->withMax(0)->withBasis(0)->withShrink(0));
        self::assertSame([0], $this->mainSizes($fixedAtZero, Rect::fromSize(1, 1)));
    }

    #[Test]
    public function builderNoOpsPreserveIdentity(): void
    {
        $flex = Flex::row();

        self::assertSame($flex, $flex->withWrap(false));
        self::assertSame($flex, $flex->withGap(0));
        self::assertSame($flex, $flex->withJustification(Justification::Start));
        self::assertNotSame($flex, $flex->withJustification(Justification::End));
    }

    /**
     * @param array{basis?: int, min?: int, max?: int, flex?: int, shrink?: int} $arguments
     */
    #[DataProvider('invalidConstraintArguments')]
    #[Test]
    public function everyNegativeConstraintValueIsRejected(array $arguments): void
    {
        $this->expectException(InvalidArgumentException::class);

        $constraint = Constraint::min($arguments['min'] ?? 0);
        foreach ($arguments as $field => $value) {
            $constraint = match ($field) {
                'min' => $constraint,
                'max' => $constraint->withMax($value),
                'flex' => $constraint->withFlex($value),
                'basis' => $constraint->withBasis($value),
                'shrink' => $constraint->withShrink($value),
            };
        }
    }

    /**
     * @return iterable<string, array{array{basis?: int, min?: int, max?: int, flex?: int, shrink?: int}}>
     */
    public static function invalidConstraintArguments(): iterable
    {
        yield 'basis' => [['basis' => -1]];
        yield 'minimum' => [['min' => -1]];
        yield 'maximum' => [['max' => -1]];
        yield 'flex weight' => [['flex' => -1]];
        yield 'shrink weight' => [['shrink' => -1]];
    }

    #[Test]
    public function negativeGapIsRejectedButEqualItemBoundsAreValid(): void
    {
        $fixed = Flex::row()->add(Text::new('fixed'), Constraint::min(3)->withMax(3)->withFlex()->withBasis(3));
        self::assertSame([3], $this->mainSizes($fixed, Rect::fromSize(5, 1)));

        $this->expectException(InvalidArgumentException::class);
        Flex::row()->withGap(-1);
    }

    #[Test]
    public function rowGrowsItemsFromTheirBasisAndReservesTheGap(): void
    {
        $first = Text::new('first');
        $second = Text::new('second');
        $flex = Flex::row()
            ->withGap(2)
            ->add($first, Constraint::flex()->withBasis(20))
            ->add($second, Constraint::flex()->withBasis(20));

        $children = $flex->computeChildRects(new Rect(0, 0, 100, 10));

        self::assertSame($first, $children[0][0]);
        self::assertEquals(new Rect(0, 0, 49, 10), $children[0][1]);
        self::assertSame($second, $children[1][0]);
        self::assertEquals(new Rect(51, 0, 49, 10), $children[1][1]);
    }

    #[Test]
    public function theGapBetweenWrappedLinesGivesWaySoEveryLineKeepsARow(): void
    {
        $flex = Flex::row()
            ->withWrap()
            ->withGap(5)
            ->add($this->constraintWidget(Constraint::exact(4)), Constraint::exact(4))
            ->add($this->constraintWidget(Constraint::exact(4)), Constraint::exact(4));
        $linesAt = static fn (int $height): array => array_map(
            static fn (array $child): array => [$child[1]->y, $child[1]->height],
            $flex->computeChildRects(new Rect(0, 0, 6, $height)),
        );

        self::assertSame([[0, 1], [6, 1]], $linesAt(7), 'room for the whole gap and a row on each side');
        self::assertSame([[0, 1], [1, 1]], $linesAt(2), 'no room for any gap at all');
        self::assertSame([[0, 0], [0, 1]], $linesAt(1), 'not even a row each');
    }

    #[Test]
    public function itemsTooLargeToCountStillWrapOntoLinesOfTheirOwn(): void
    {
        $flex = Flex::row()
            ->withWrap()
            ->add(Text::new('a'), Constraint::exact(PHP_INT_MAX))
            ->add(Text::new('b'), Constraint::exact(PHP_INT_MAX));

        self::assertEquals(
            [new Rect(0, 0, 10, 1), new Rect(0, 1, 10, 1)],
            array_column($flex->computeChildRects(new Rect(0, 0, 10, 2)), 1),
        );
    }

    #[Test]
    public function anItemGivenNoWidthAddsNoRowsToItsLine(): void
    {
        $flex = Flex::row()->add($this->constraintWidget(Constraint::exact(3)), Constraint::exact(0));

        self::assertSame(0, $flex->measureHeight(10));
    }

    #[Test]
    public function aColumnOfItemsThatAskForNoWidthStillClaimsACell(): void
    {
        $flex = Flex::column()
            ->withWrap()
            ->add($this->constraintWidget(Constraint::exact(0)), Constraint::exact(5))
            ->add($this->constraintWidget(Constraint::exact(3)), Constraint::exact(5));

        self::assertSame([4, 6], array_map(
            static fn (array $child): int => $child[1]->width,
            $flex->computeChildRects(new Rect(0, 0, 10, 6)),
        ));
    }

    #[Test]
    public function wrappingReflowsThreeItemsFromOneLineToTwoAndThree(): void
    {
        $first = Text::new('first');
        $second = Text::new('second');
        $third = Text::new('third');
        $flex = Flex::row()
            ->withWrap()
            ->withGap(2)
            ->add($first, Constraint::min(20)->withFlex()->withBasis(30))
            ->add($second, Constraint::min(20)->withFlex()->withBasis(30))
            ->add($third, Constraint::min(20)->withFlex()->withBasis(30));

        $wide = $flex->computeChildRects(new Rect(0, 0, 100, 12));
        self::assertEquals(new Rect(0, 0, 32, 12), $wide[0][1]);
        self::assertEquals(new Rect(34, 0, 32, 12), $wide[1][1]);
        self::assertEquals(new Rect(68, 0, 32, 12), $wide[2][1]);

        $medium = $flex->computeChildRects(new Rect(0, 0, 70, 12));
        self::assertEquals(new Rect(0, 0, 34, 5), $medium[0][1]);
        self::assertEquals(new Rect(36, 0, 34, 5), $medium[1][1]);
        self::assertEquals(new Rect(0, 7, 70, 5), $medium[2][1]);

        $narrow = $flex->computeChildRects(new Rect(0, 0, 35, 12));
        self::assertEquals(new Rect(0, 0, 35, 2), $narrow[0][1]);
        self::assertEquals(new Rect(0, 4, 35, 3), $narrow[1][1]);
        self::assertEquals(new Rect(0, 9, 35, 3), $narrow[2][1]);
    }

    #[Test]
    public function contentSizedChildrenWrapAtTheWidthTheyAskFor(): void
    {
        $flex = Flex::row()
            ->withWrap()
            ->add(Text::new('alpha-one'), Constraint::content())
            ->add(Text::new('bravo-two'), Constraint::content())
            ->add(Text::new('charlie-3'), Constraint::content());

        $rects = array_map(static fn (array $placed): Rect => $placed[1], $flex->computeChildRects(new Rect(0, 0, 20, 4)));

        self::assertSame([[9, 0], [9, 0], [9, 2]], array_map(static fn (Rect $rect): array => [$rect->width, $rect->y], $rects));
        self::assertSame(2, $flex->measureHeight(20));
    }

    #[Test]
    public function childrenAddedWithoutAConstraintWrapAtTheWidthTheyAskFor(): void
    {
        $flex = Flex::row()
            ->withWrap()
            ->add(Text::new('alpha-one'))
            ->add(Text::new('bravo-two'))
            ->add(Text::new('charlie-3'));

        $rects = array_map(static fn (array $placed): Rect => $placed[1], $flex->computeChildRects(new Rect(0, 0, 20, 4)));

        self::assertSame([0, 0, 2], array_map(static fn (Rect $rect): int => $rect->y, $rects));
        self::assertSame(2, $flex->measureHeight(20));
    }

    #[Test]
    public function anOversizedGapStillLeavesEveryWrappedLineARow(): void
    {
        $flex = Flex::row()
            ->withWrap()
            ->withGap(10)
            ->add(Text::new('first'), Constraint::min(20)->withFlex()->withBasis(30))
            ->add(Text::new('second'), Constraint::min(20)->withFlex()->withBasis(30))
            ->add(Text::new('third'), Constraint::min(20)->withFlex()->withBasis(30));

        $children = $flex->computeChildRects(new Rect(0, 0, 35, 12));

        foreach (array_column(array_column($children, 1), 'height') as $index => $height) {
            self::assertGreaterThanOrEqual(1, $height, "wrapped line {$index} was erased by the gap");
        }
        self::assertLessThanOrEqual(12, max(array_map(
            static fn (array $child): int => $child[1]->y + $child[1]->height,
            $children,
        )), 'the lines stay inside the area');
    }

    #[Test]
    public function wrappingChangesOnlyAfterBasisAndGapCrossTheBoundary(): void
    {
        $flex = Flex::row()
            ->withWrap()
            ->withGap(2)
            ->add(Text::new('first'), Constraint::max(10)->withFlex(0)->withBasis(10))
            ->add(Text::new('second'), Constraint::max(10)->withFlex(0)->withBasis(10));

        $exact = $flex->computeChildRects(Rect::fromSize(22, 5));
        self::assertSame([0, 12], array_column(array_column($exact, 1), 'x'));
        self::assertSame([0, 0], array_column(array_column($exact, 1), 'y'));

        $oneCellShort = $flex->computeChildRects(Rect::fromSize(21, 5));
        self::assertSame([0, 0], array_column(array_column($oneCellShort, 1), 'x'));
        self::assertSame([0, 3], array_column(array_column($oneCellShort, 1), 'y'));
    }

    #[Test]
    public function crossAxisStarvedAreasCollapseToSafeRectangles(): void
    {
        $wrapped = Flex::row()
            ->withWrap()
            ->withGap(5)
            ->add(Text::new('first'), Constraint::flex()->withBasis(2))
            ->add(Text::new('second'), Constraint::flex()->withBasis(2))
            ->add(Text::new('third'), Constraint::flex()->withBasis(2));
        foreach ($wrapped->computeChildRects(Rect::fromSize(1, 1)) as [, $rect]) {
            self::assertGreaterThanOrEqual(0, $rect->height);
            self::assertLessThanOrEqual(1, $rect->bottom());
        }
    }

    #[Test]
    public function nowrapItemsShrinkEvenlyDownToTheirMinimums(): void
    {
        $flex = Flex::row()
            ->withGap(1)
            ->add(Text::new('first'), Constraint::min(20)->withFlex()->withBasis(30))
            ->add(Text::new('second'), Constraint::min(20)->withFlex()->withBasis(30));

        $children = $flex->computeChildRects(new Rect(0, 0, 51, 5));

        self::assertSame(25, $children[0][1]->width);
        self::assertSame(25, $children[1][1]->width);
        self::assertSame(26, $children[1][1]->x);
    }

    #[Test]
    public function impossibleMinimumsDegradeInsideTheAvailableTerminalArea(): void
    {
        $flex = Flex::row()
            ->withGap(1)
            ->add(Text::new('first'), Constraint::min(15)->withFlex()->withBasis(30))
            ->add(Text::new('second'), Constraint::min(15)->withFlex()->withBasis(30));

        $children = $flex->computeChildRects(new Rect(0, 0, 21, 5));

        self::assertSame(10, $children[0][1]->width);
        self::assertSame(10, $children[1][1]->width);
        self::assertSame(21, $children[1][1]->right());
    }

    #[Test]
    public function centerJustificationPlacesBoundedItemsInTheMiddle(): void
    {
        $flex = Flex::row()
            ->withGap(2)
            ->withJustification(Justification::Center)
            ->add(Text::new('first'), Constraint::max(10)->withBasis(10))
            ->add(Text::new('second'), Constraint::max(10)->withBasis(10));

        $children = $flex->computeChildRects(new Rect(0, 0, 30, 5));

        self::assertEquals(new Rect(4, 0, 10, 5), $children[0][1]);
        self::assertEquals(new Rect(16, 0, 10, 5), $children[1][1]);
    }

    #[Test]
    public function spaceBetweenJustificationDistributesUnusedCellsBetweenItems(): void
    {
        $flex = Flex::row()
            ->withGap(2)
            ->withJustification(Justification::SpaceBetween)
            ->add(Text::new('first'), Constraint::max(5)->withBasis(5))
            ->add(Text::new('second'), Constraint::max(5)->withBasis(5))
            ->add(Text::new('third'), Constraint::max(5)->withBasis(5));

        $children = $flex->computeChildRects(new Rect(0, 0, 25, 3));

        self::assertSame([0, 10, 20], array_map(
            static fn (array $child): int => $child[1]->x,
            $children,
        ));
    }

    #[Test]
    public function everyJustificationModeHasDeterministicIntegerRemainders(): void
    {
        $make = static fn (Justification $justify): Flex => Flex::row()
            ->withJustification($justify)
            ->add(Text::new('first'), Constraint::max(2)->withFlex(0)->withBasis(2))
            ->add(Text::new('second'), Constraint::max(2)->withFlex(0)->withBasis(2));

        self::assertSame([0, 2], $this->mainOffsets($make(Justification::Start), Rect::fromSize(9, 1)));
        self::assertSame([5, 7], $this->mainOffsets($make(Justification::End), Rect::fromSize(9, 1)));
        self::assertSame([2, 4], $this->mainOffsets($make(Justification::Center), Rect::fromSize(9, 1)));
        self::assertSame([0, 7], $this->mainOffsets($make(Justification::SpaceBetween), Rect::fromSize(9, 1)));
        self::assertSame([1, 5], $this->mainOffsets($make(Justification::SpaceEvenly), Rect::fromSize(9, 1)));
        self::assertSame([1, 4], $this->mainOffsets($make(Justification::SpaceEvenly), Rect::fromSize(8, 1)));

        $single = Flex::row()
            ->withJustification(Justification::SpaceBetween)
            ->add(Text::new('only'), Constraint::max(2)->withFlex(0)->withBasis(2));
        self::assertSame([0], $this->mainOffsets($single, Rect::fromSize(9, 1)));
    }

    #[Test]
    public function weightedGrowthAndShrinkAreObservable(): void
    {
        $grow = Flex::row()
            ->add(Text::new('one'), Constraint::flex()->withBasis(2))
            ->add(Text::new('two'), Constraint::flex(2)->withBasis(2));
        self::assertSame([4, 6], $this->mainSizes($grow, Rect::fromSize(10, 1)));

        $shrink = Flex::row()
            ->add(Text::new('one'), Constraint::min(1)->withFlex()->withBasis(6)->withShrink(1))
            ->add(Text::new('two'), Constraint::min(1)->withFlex()->withBasis(6)->withShrink(2));
        self::assertSame([5, 3], $this->mainSizes($shrink, Rect::fromSize(8, 1)));

        $nonShrinking = Flex::row()
            ->add(Text::new('fixed'), Constraint::min(0)->withFlex()->withBasis(6)->withShrink(0))
            ->add(Text::new('flexible'), Constraint::min(0)->withFlex()->withBasis(6)->withShrink(1));
        self::assertSame([6, 2], $this->mainSizes($nonShrinking, Rect::fromSize(8, 1)));

        $minimumPriority = Flex::row()
            ->add(Text::new('bounded'), Constraint::min(7)->withFlex()->withBasis(8)->withShrink(1))
            ->add(Text::new('flexible'), Constraint::min(0)->withFlex()->withBasis(8)->withShrink(1));
        self::assertSame([7, 3], $this->mainSizes($minimumPriority, Rect::fromSize(10, 1)));

        $belowMinimumWeights = Flex::row()
            ->add(Text::new('one'), Constraint::min(8)->withFlex()->withBasis(8)->withShrink(1))
            ->add(Text::new('two'), Constraint::min(4)->withFlex()->withBasis(4)->withShrink(10));
        self::assertSame([8, 1], $this->mainSizes($belowMinimumWeights, Rect::fromSize(9, 1)));
        self::assertSame([8, 0], $this->mainSizes($belowMinimumWeights, Rect::fromSize(8, 1)));

        $smallWeightedShrink = Flex::row()
            ->add(Text::new('one'), Constraint::flex()->withBasis(1)->withShrink(1))
            ->add(Text::new('two'), Constraint::flex()->withBasis(2)->withShrink(1));
        self::assertSame([1, 0], $this->mainSizes($smallWeightedShrink, Rect::fromSize(1, 1)));
    }

    #[Test]
    public function anExactSizeHoldsWhileItsNeighboursGiveWayInARowTooNarrowForAll(): void
    {
        $flex = Flex::row()
            ->add(Text::new('a long label here'), Constraint::min(8)->withFlex())
            ->add(Text::new('fixed'), Constraint::exact(10));

        self::assertSame([2, 10], $this->mainSizes($flex, Rect::fromSize(12, 1)));
    }

    #[Test]
    public function zeroGrowItemsWithHeadroomLeaveSpaceForJustification(): void
    {
        $flex = Flex::row()
            ->withJustification(Justification::End)
            ->add(Text::new('fixed'), Constraint::max(10)->withFlex(0)->withBasis(2));

        self::assertEquals(new Rect(3, 0, 2, 1), $flex->computeChildRects(Rect::fromSize(5, 1))[0][1]);
    }

    #[Test]
    public function aRowIsNoTallerThanTheRowsItsItemPinnedItselfTo(): void
    {
        $row = Flex::row()->add($this->widgetMeasuringPastItsOwnCeiling(), Constraint::flex());

        self::assertSame(
            2,
            $row->measureHeight(20),
            'a measurement says what the content wants; the child still holds itself to two rows',
        );
    }

    #[Test]
    public function integerRemaindersStopAfterTheAvailableCellsAreAssigned(): void
    {
        $grow = Flex::row()
            ->add(Text::new('first'), Constraint::flex())
            ->add(Text::new('second'), Constraint::flex())
            ->add(Text::new('third'), Constraint::flex());
        self::assertSame([0, 0, 1], $this->mainSizes($grow, Rect::fromSize(1, 1)));

        $shrink = Flex::row()
            ->add(Text::new('first'), Constraint::flex()->withBasis(1))
            ->add(Text::new('second'), Constraint::flex()->withBasis(1))
            ->add(Text::new('third'), Constraint::flex()->withBasis(1));
        self::assertSame([1, 1, 0], $this->mainSizes($shrink, Rect::fromSize(2, 1)));
    }

    #[Test]
    public function growthSkipsMaxedItemsAndRedistributesSpaceToSiblings(): void
    {
        $flex = Flex::row()
            ->add(Text::new('bounded'), Constraint::max(6)->withBasis(5))
            ->add(Text::new('flexible'), Constraint::flex()->withBasis(5));

        $children = $flex->computeChildRects(new Rect(0, 0, 20, 3));

        self::assertSame(6, $children[0][1]->width);
        self::assertSame(14, $children[1][1]->width);
        self::assertSame(20, $children[1][1]->right());
    }

    #[Test]
    public function terminalBoundsWinEvenWhenItemsDisableNormalShrinking(): void
    {
        $flex = Flex::row()
            ->withGap(1)
            ->add(Text::new('first'), Constraint::min(20)->withFlex()->withBasis(20)->withShrink(0))
            ->add(Text::new('second'), Constraint::min(20)->withFlex()->withBasis(20)->withShrink(0));

        $children = $flex->computeChildRects(new Rect(0, 0, 15, 3));

        self::assertSame(15, $children[1][1]->right());
        self::assertGreaterThanOrEqual(0, $children[0][1]->width);
        self::assertGreaterThanOrEqual(0, $children[1][1]->width);
    }

    #[Test]
    public function enormousDeclaredSizesAreFittedWithoutIntegerOverflow(): void
    {
        $flex = Flex::row()
            ->add(Text::new('first'), Constraint::flex()->withBasis(PHP_INT_MAX))
            ->add(Text::new('second'), Constraint::flex()->withBasis(PHP_INT_MAX));

        $children = $flex->computeChildRects(Rect::fromSize(10, 3));

        self::assertSame(5, $children[0][1]->width);
        self::assertSame(5, $children[1][1]->width);
        self::assertSame(10, $children[1][1]->right());
    }

    #[Test]
    public function forcedFittingPreservesProportionsAndAssignsRemaindersRearmost(): void
    {
        $flex = Flex::row()
            ->add(Text::new('first'), Constraint::min(6)->withFlex()->withBasis(6)->withShrink(0))
            ->add(Text::new('second'), Constraint::min(3)->withFlex()->withBasis(3)->withShrink(0))
            ->add(Text::new('third'), Constraint::min(1)->withFlex()->withBasis(1)->withShrink(0));

        self::assertSame([3, 1, 1], $this->mainSizes($flex, Rect::fromSize(5, 1)));

        $differentRemainder = Flex::row()
            ->add(Text::new('first'), Constraint::min(5)->withFlex()->withBasis(5)->withShrink(0))
            ->add(Text::new('second'), Constraint::min(4)->withFlex()->withBasis(4)->withShrink(0))
            ->add(Text::new('third'), Constraint::min(1)->withFlex()->withBasis(1)->withShrink(0));
        self::assertSame([2, 2, 1], $this->mainSizes($differentRemainder, Rect::fromSize(5, 1)));
    }

    #[Test]
    public function zeroSizedAreaReturnsOnlyZeroSizedBoundedRects(): void
    {
        $flex = Flex::row()
            ->withGap(5)
            ->add(Text::new('first'), Constraint::flex()->withBasis(10))
            ->add(Text::new('second'), Constraint::flex()->withBasis(10));

        $children = $flex->computeChildRects(new Rect(4, 7, 0, 0));

        self::assertEquals(new Rect(4, 7, 0, 0), $children[0][1]);
        self::assertEquals(new Rect(4, 7, 0, 0), $children[1][1]);

        $positiveDesiredHeight = Flex::row()->add(Text::new('item'), Constraint::flex()->withBasis(2));
        self::assertEquals(
            new Rect(0, 0, 10, 0),
            $positiveDesiredHeight->computeChildRects(Rect::fromSize(10, 0))[0][1],
        );
    }

    #[Test]
    public function columnUsesTheSameBasisGrowAndGapModel(): void
    {
        $flex = Flex::column()
            ->withGap(1)
            ->add(Text::new('first'), Constraint::flex()->withBasis(3))
            ->add(Text::new('second'), Constraint::flex()->withBasis(3));

        $children = $flex->computeChildRects(new Rect(0, 0, 20, 11));

        self::assertEquals(new Rect(0, 0, 20, 5), $children[0][1]);
        self::assertEquals(new Rect(0, 6, 20, 5), $children[1][1]);
    }

    #[Test]
    public function wrappedColumnCreatesNewColumnsAndPreservesNonzeroOrigin(): void
    {
        $flex = Flex::column()
            ->withWrap()
            ->withGap(1)
            ->add(Text::new('first'), Constraint::max(3)->withFlex(0)->withBasis(3))
            ->add(Text::new('second'), Constraint::max(3)->withFlex(0)->withBasis(3))
            ->add(Text::new('third'), Constraint::max(3)->withFlex(0)->withBasis(3));

        $children = $flex->computeChildRects(new Rect(4, 7, 8, 7));

        self::assertEquals(new Rect(4, 7, 3, 3), $children[0][1]);
        self::assertEquals(new Rect(4, 11, 3, 3), $children[1][1]);
        self::assertEquals(new Rect(8, 7, 4, 3), $children[2][1]);
    }

    #[Test]
    public function columnWrappingUsesEqualColumnPreferencesNotChildHeightMeasurements(): void
    {
        $flex = Flex::column()
            ->withWrap()
            ->withGap(1)
            ->add($this->measuredWidget(static fn (): int => 20), Constraint::max(2)->withFlex(0)->withBasis(2))
            ->add($this->measuredWidget(static fn (): int => 1), Constraint::max(2)->withFlex(0)->withBasis(2));

        $children = $flex->computeChildRects(Rect::fromSize(7, 2));

        self::assertEquals(new Rect(0, 0, 3, 2), $children[0][1]);
        self::assertEquals(new Rect(4, 0, 3, 2), $children[1][1]);
    }

    #[Test]
    public function aColumnGivesAContentSizedChildTheHeightItMeasures(): void
    {
        $flex = Flex::column()
            ->add($this->measuredWidget(static fn (): int => 3), Constraint::content())
            ->add(Text::new('rest'), Constraint::flex());

        $children = $flex->computeChildRects(Rect::fromSize(20, 10));

        self::assertSame(3, $children[0][1]->height, 'A child sized to its content takes the rows it measures');
        self::assertSame(7, $children[1][1]->height, 'and its sibling absorbs the rest');
    }

    #[Test]
    public function columnCrossSizesFollowIntrinsicChildWidths(): void
    {
        $flex = Flex::column()
            ->withWrap()
            ->withGap(1)
            ->add($this->widthMeasuredWidget(2), Constraint::max(2)->withFlex(0)->withBasis(2))
            ->add($this->widthMeasuredWidget(4), Constraint::max(2)->withFlex(0)->withBasis(2));

        $children = $flex->computeChildRects(Rect::fromSize(7, 2));

        self::assertEquals(new Rect(0, 0, 2, 2), $children[0][1]);
        self::assertEquals(new Rect(3, 0, 4, 2), $children[1][1]);
    }

    #[Test]
    public function rowCrossSizesFollowIntrinsicChildHeights(): void
    {
        $flex = Flex::row()
            ->withWrap()
            ->withGap(1)
            ->add($this->measuredWidget(static fn (): int => 2), Constraint::max(3)->withFlex(0)->withBasis(3))
            ->add($this->measuredWidget(static fn (): int => 4), Constraint::max(3)->withFlex(0)->withBasis(3));

        $children = $flex->computeChildRects(Rect::fromSize(3, 7));

        self::assertEquals(new Rect(0, 0, 3, 2), $children[0][1]);
        self::assertEquals(new Rect(0, 3, 3, 4), $children[1][1]);
    }

    #[Test]
    public function aRowInAVerticalLayoutTakesTheRowsItsTallestItemNeeds(): void
    {
        $cards = Flex::row()
            ->add(Box::new()->add(Text::new('one')))
            ->add(Box::new()->add(Text::new('two')));
        $layout = Layout::column($cards, Text::new('below'), Spacer::new());

        $placed = $layout->placedChildren(Rect::fromSize(40, 20));

        self::assertSame(3, $placed[0][1]->height);
        self::assertSame(3, $placed[1][1]->y);
    }

    #[Test]
    public function aShortItemKeepsItsOwnHeightWhenItemsAreNotStretched(): void
    {
        $cards = Flex::row()
            ->add(Box::new()->add(Text::new('one')))
            ->add(Box::new()->add(Text::new('two'))->add(Text::new('more'))->add(Text::new('most')));
        $area = Rect::fromSize(40, 5);

        $stretched = $cards->computeChildRects($area);
        $atTheTop = $cards->withCrossAlignment(CrossAlignment::Start)->computeChildRects($area);
        $centred = $cards->withCrossAlignment(CrossAlignment::Center)->computeChildRects($area);
        $atTheBottom = $cards->withCrossAlignment(CrossAlignment::End)->computeChildRects($area);

        self::assertEquals(new Rect(0, 0, 20, 5), $stretched[0][1]);
        self::assertEquals(new Rect(0, 0, 20, 3), $atTheTop[0][1]);
        self::assertEquals(new Rect(0, 1, 20, 3), $centred[0][1]);
        self::assertEquals(new Rect(0, 2, 20, 3), $atTheBottom[0][1]);
        self::assertEquals(new Rect(20, 0, 20, 5), $atTheTop[1][1]);
    }

    #[Test]
    public function configuredGapIsClampedUsingTheNumberOfActualGaps(): void
    {
        $single = Flex::row()
            ->withGap(5)
            ->add(Text::new('only'), Constraint::max(1)->withFlex(0)->withBasis(1));
        self::assertSame([0], $this->mainOffsets($single, Rect::fromSize(3, 1)));

        $pair = Flex::row()
            ->withGap(5)
            ->add(Text::new('first'), Constraint::flex()->withBasis(1))
            ->add(Text::new('second'), Constraint::flex()->withBasis(1));
        $children = $pair->computeChildRects(Rect::fromSize(3, 1));
        self::assertSame([0, 3], array_map(static fn (array $child): int => $child[1]->x, $children));
        self::assertSame([0, 0], array_map(static fn (array $child): int => $child[1]->width, $children));
    }

    #[Test]
    public function wrappedRowMeasurementUsesTheSameLinePacking(): void
    {
        $flex = Flex::row()
            ->withWrap()
            ->withGap(2)
            ->add(Text::new('first'), Constraint::flex()->withBasis(30))
            ->add(Text::new('second'), Constraint::flex()->withBasis(30))
            ->add(Text::new('third'), Constraint::flex()->withBasis(30));

        self::assertSame(1, $flex->measureHeight(100));
        self::assertSame(4, $flex->measureHeight(70));
    }

    #[Test]
    public function measurementHandlesEmptyZeroSingleCellColumnAndIntrinsicHeights(): void
    {
        self::assertSame(0, Flex::row()->measureHeight(10));

        $measured = Flex::row()
            ->withWrap()
            ->withGap(1)
            ->add($this->measuredWidget(static fn (int $width): int => $width), Constraint::max(2)->withFlex(0)->withBasis(2))
            ->add($this->measuredWidget(static fn (int $width): int => $width + 1), Constraint::max(2)->withFlex(0)->withBasis(2));

        self::assertSame(0, $measured->measureHeight(0));
        self::assertSame(4, $measured->measureHeight(1));
        self::assertSame(3, $measured->measureHeight(5));
        self::assertSame(6, $measured->measureHeight(4));

        $column = Flex::column()
            ->withGap(2)
            ->add(Text::new('first'), Constraint::min(4)->withMax(7)->withFlex()->withBasis(3))
            ->add(Text::new('second'), Constraint::min(2)->withMax(6)->withFlex()->withBasis(9));
        self::assertSame(12, $column->measureHeight(1));

        $negativeMeasure = Flex::row()
            ->add($this->measuredWidget(static fn (): int => -2), Constraint::max(1)->withFlex(0)->withBasis(1));
        self::assertSame(0, $negativeMeasure->measureHeight(1));

        $constraints = Flex::row()
            ->withWrap()
            ->withGap(1)
            ->add($this->constraintWidget(Constraint::exact(0)), Constraint::max(1)->withFlex(0)->withBasis(1))
            ->add($this->constraintWidget(Constraint::flex()), Constraint::max(1)->withFlex(0)->withBasis(1));
        self::assertSame(2, $constraints->measureHeight(1));

        $exactZero = Flex::row()
            ->add($this->constraintWidget(Constraint::exact(0)), Constraint::max(1)->withFlex(0)->withBasis(1));
        $flexibleZero = Flex::row()
            ->add($this->constraintWidget(Constraint::flex()), Constraint::max(1)->withFlex(0)->withBasis(1));
        self::assertSame(0, $exactZero->measureHeight(1));
        self::assertSame(1, $flexibleZero->measureHeight(1));
    }

    #[Test]
    public function aColumnOfContentSizedItemsMeasuresItsChildren(): void
    {
        $column = Flex::column()
            ->withGap(1)
            ->add($this->measuredWidget(static fn (int $width): int => 3), Constraint::min(0))
            ->add($this->measuredWidget(static fn (int $width): int => $width), Constraint::min(0));

        self::assertSame(7, $column->measureHeight(3));
    }

    #[Test]
    public function widgetTreeObservesTheSameWrappedChildRectsAsRendering(): void
    {
        $rendered = [];
        $first = $this->areaSpy($rendered);
        $second = $this->areaSpy($rendered);
        $flex = Flex::row()
            ->withWrap()
            ->withGap(1)
            ->add($first, Constraint::flex()->withBasis(20))
            ->add($second, Constraint::flex()->withBasis(20));
        $area = new Rect(3, 2, 25, 8);

        $frame = new Frame(40, 12);
        $flex->render($frame, $area, new RenderContext(Theme::dark()));
        $rects = WidgetTree::resolveRects($flex, $area);

        self::assertCount(2, $rendered);
        self::assertEquals($rendered[0], $rects[$first]);
        self::assertEquals($rendered[1], $rects[$second]);
        self::assertSame(2, $rendered[0]->y);
        self::assertGreaterThan($rendered[0]->y, $rendered[1]->y);
    }

    #[Test]
    public function buildersAreImmutableAndRejectInvalidItemRanges(): void
    {
        $empty = Flex::row();
        $configured = $empty->withWrap()->withGap(2)->add(Text::new('item'), Constraint::flex()->withBasis(10));

        self::assertNotSame($empty, $configured);
        self::assertCount(0, $empty->computeChildRects(Rect::fromSize(20, 5)));
        self::assertCount(1, $configured->computeChildRects(Rect::fromSize(20, 5)));

        $this->expectException(InvalidArgumentException::class);
        $empty->add(Text::new('invalid'), Constraint::min(12)->withMax(11));
    }

    /**
     * @param list<Rect> $rendered
     */
    private function areaSpy(array &$rendered): Widget
    {
        $record = static function (Rect $area) use (&$rendered): void {
            $rendered[] = $area;
        };

        return new class($record) implements Widget {
            public function __construct(private readonly Closure $record)
            {
            }

            public function sizing(): Sizing
            {
                return Sizing::of(Constraint::flex(), Constraint::flex());
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
                ($this->record)($area);
            }
        };
    }

    private function widthMeasuredWidget(int $width): Widget
    {
        return new class($width) implements Widget {
            public function __construct(private readonly int $width)
            {
            }

            public function sizing(): Sizing
            {
                return Sizing::of(Constraint::flex()->withBasis($this->width), Constraint::flex());
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
            }
        };
    }

    private function widgetMeasuringPastItsOwnCeiling(): Widget
    {
        return new class implements Widget, MeasureHeight {
            public function sizing(): Sizing
            {
                return Sizing::of(Constraint::flex(), Constraint::exact(2));
            }

            public function measureHeight(int $width): int
            {
                return 10;
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
            }
        };
    }

    private function measuredWidget(Closure $measure): Widget
    {
        return new class($measure) implements Widget, MeasureHeight {
            public function __construct(private readonly Closure $measure)
            {
            }

            public function sizing(): Sizing
            {
                return Sizing::of(Constraint::flex(), Constraint::flex());
            }

            public function measureHeight(int $width): int
            {
                return ($this->measure)($width);
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
            }
        };
    }

    private function constraintWidget(Constraint $constraint): Widget
    {
        return new class($constraint) implements Widget {
            public function __construct(private readonly Constraint $constraint)
            {
            }

            public function sizing(): Sizing
            {
                return Sizing::of($this->constraint, $this->constraint);
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
            }
        };
    }

    /**
     * @return list<int>
     */
    private function mainSizes(Flex $flex, Rect $area): array
    {
        return array_map(
            static fn (array $child): int => $child[1]->width,
            $flex->computeChildRects($area),
        );
    }

    /**
     * @return list<int>
     */
    private function mainOffsets(Flex $flex, Rect $area): array
    {
        return array_map(
            static fn (array $child): int => $child[1]->x,
            $flex->computeChildRects($area),
        );
    }
}
