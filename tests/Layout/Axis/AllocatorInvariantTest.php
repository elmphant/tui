<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Layout\Axis;

use Elmphant\Tui\Layout\Axis\Allocator;
use Elmphant\Tui\Layout\Axis\AxisSpecification;
use Elmphant\Tui\Layout\Axis\ItemPolicy;
use Elmphant\Tui\Layout\Axis\Overflow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AllocatorInvariantTest extends TestCase
{
    /**
     * @param list<ItemPolicy> $policies
     */
    #[Test]
    #[DataProvider('cases')]
    public function noSlotIsGivenANegativeSize(array $policies, AxisSpecification $specification, int $extent): void
    {
        foreach (Allocator::resolve($policies, $extent, $specification)->sizes as $index => $size) {
            self::assertGreaterThanOrEqual(0, $size, sprintf('Slot %d was sized %d', $index, $size));
        }
    }

    /**
     * @param list<ItemPolicy> $policies
     */
    #[Test]
    #[DataProvider('cases')]
    public function theFirstSlotStartsAtOrAfterTheAxisOrigin(array $policies, AxisSpecification $specification, int $extent): void
    {
        $offsets = Allocator::resolve($policies, $extent, $specification)->offsets;

        self::assertGreaterThanOrEqual(0, $offsets[0], 'The first slot starts before the axis');
    }

    /**
     * @param list<ItemPolicy> $policies
     */
    #[Test]
    #[DataProvider('cases')]
    public function noSlotStartsBeforeTheOneAheadOfIt(array $policies, AxisSpecification $specification, int $extent): void
    {
        $offsets = Allocator::resolve($policies, $extent, $specification)->offsets;
        $ascending = $offsets;
        sort($ascending);

        self::assertSame($ascending, $offsets, 'The slots are not laid out in order');
    }

    /**
     * @param list<ItemPolicy> $policies
     */
    #[Test]
    #[DataProvider('cases')]
    public function noSlotReachesIntoTheNextOne(array $policies, AxisSpecification $specification, int $extent): void
    {
        $resolved = Allocator::resolve($policies, $extent, $specification);
        $reaching = [];

        for ($index = 1; $index < count($resolved->offsets); ++$index) {
            $end = $resolved->offsets[$index - 1] + $resolved->sizes[$index - 1];
            if ($end > $resolved->offsets[$index]) {
                $reaching[] = sprintf('slot %d ends at %d but slot %d starts at %d', $index - 1, $end, $index, $resolved->offsets[$index]);
            }
        }

        self::assertSame([], $reaching);
    }

    /**
     * @param list<ItemPolicy> $policies
     */
    #[Test]
    #[DataProvider('cases')]
    public function theReportedOverrunIsWhatTheSizesTakeBeyondTheCellsLeftByTheGaps(array $policies, AxisSpecification $specification, int $extent): void
    {
        $resolved = Allocator::resolve($policies, $extent, $specification);
        $forSlots = AllocatorSweep::cellsForSlots($policies, $specification, $extent);

        self::assertSame(max(0, array_sum($resolved->sizes) - $forSlots), $resolved->overflow);
    }

    /**
     * @param list<ItemPolicy> $policies
     */
    #[Test]
    #[DataProvider('clippedCases')]
    public function clippingTheTailNeverTakesMoreThanTheCellsLeftByTheGaps(array $policies, AxisSpecification $specification, int $extent): void
    {
        $resolved = Allocator::resolve($policies, $extent, $specification);

        self::assertLessThanOrEqual(
            AllocatorSweep::cellsForSlots($policies, $specification, $extent),
            array_sum($resolved->sizes),
        );
    }

    /**
     * @param list<ItemPolicy> $policies
     */
    #[Test]
    #[DataProvider('squeezedCases')]
    public function squeezingToTheFloorNeverTakesMoreThanTheCellsLeftByTheGaps(array $policies, AxisSpecification $specification, int $extent): void
    {
        $resolved = Allocator::resolve($policies, $extent, $specification);

        self::assertLessThanOrEqual(
            AllocatorSweep::cellsForSlots($policies, $specification, $extent),
            array_sum($resolved->sizes),
        );
    }

    /**
     * @param list<ItemPolicy> $policies
     */
    #[Test]
    #[DataProvider('compressedCasesWhereEveryFloorFitsAndEverySlotYields')]
    public function compressingReportsNoOverrunOnceEveryFloorFitsAndEverySlotWillGiveWay(array $policies, AxisSpecification $specification, int $extent): void
    {
        self::assertSame(0, Allocator::resolve($policies, $extent, $specification)->overflow);
    }

    /**
     * @param list<ItemPolicy> $policies
     */
    #[Test]
    #[DataProvider('casesWhoseSlotsFitTheAxis')]
    public function theLastSlotEndsInsideTheAxisWheneverTheSizesFit(array $policies, AxisSpecification $specification, int $extent): void
    {
        $resolved = Allocator::resolve($policies, $extent, $specification);
        $last = count($resolved->sizes) - 1;

        self::assertLessThanOrEqual($extent, $resolved->offsets[$last] + $resolved->sizes[$last]);
    }

    /**
     * @param list<ItemPolicy> $policies
     */
    #[Test]
    #[DataProvider('cases')]
    public function noSlotIsSizedPastItsCeiling(array $policies, AxisSpecification $specification, int $extent): void
    {
        $sizes = Allocator::resolve($policies, $extent, $specification)->sizes;

        foreach ($policies as $index => $policy) {
            self::assertLessThanOrEqual($policy->max, $sizes[$index], sprintf('Slot %d was sized %d', $index, $sizes[$index]));
        }
    }

    /**
     * @param list<ItemPolicy> $policies
     */
    #[Test]
    #[DataProvider('casesWhereEveryFloorFitsAndNothingIsClippedOrSqueezed')]
    public function noSlotIsCutBelowItsFloorWhileCompressingOrRetaining(array $policies, AxisSpecification $specification, int $extent): void
    {
        $sizes = Allocator::resolve($policies, $extent, $specification)->sizes;

        foreach ($policies as $index => $policy) {
            self::assertGreaterThanOrEqual($policy->min, $sizes[$index], sprintf('Slot %d was sized %d', $index, $sizes[$index]));
        }
    }

    /**
     * @param list<ItemPolicy> $policies
     */
    #[Test]
    #[DataProvider('cases')]
    public function anAxisResolvedAfterOtherAxesLandsWhereItDidBeforeThem(array $policies, AxisSpecification $specification, int $extent): void
    {
        $first = Allocator::resolve($policies, $extent, $specification);

        foreach (AllocatorSweep::intervening() as [$otherPolicies, $otherSpecification, $otherExtent]) {
            Allocator::resolve($otherPolicies, $otherExtent, $otherSpecification);
        }

        self::assertEquals($first, Allocator::resolve($policies, $extent, $specification));
    }

    /**
     * @return iterable<string, array{list<ItemPolicy>, AxisSpecification, int}>
     */
    public static function cases(): iterable
    {
        return AllocatorSweep::cases();
    }

    /**
     * @return iterable<string, array{list<ItemPolicy>, AxisSpecification, int}>
     */
    public static function casesWhereEveryFloorFitsAndNothingIsClippedOrSqueezed(): iterable
    {
        foreach (AllocatorSweep::casesWhereEveryFloorFits() as $name => [$policies, $specification, $extent]) {
            if ($specification->overflow === Overflow::Compress || $specification->overflow === Overflow::Retain) {
                yield $name => [$policies, $specification, $extent];
            }
        }
    }

    /**
     * @return iterable<string, array{list<ItemPolicy>, AxisSpecification, int}>
     */
    public static function casesWhereEveryBasisFits(): iterable
    {
        return AllocatorSweep::casesWhereEveryBasisFits();
    }

    /**
     * @return iterable<string, array{list<ItemPolicy>, AxisSpecification, int}>
     */
    public static function retainedCases(): iterable
    {
        return AllocatorSweep::casesUnder(Overflow::Retain);
    }

    /**
     * @return iterable<string, array{list<ItemPolicy>, AxisSpecification, int}>
     */
    public static function casesWhoseSlotsFitTheAxis(): iterable
    {
        foreach (AllocatorSweep::cases() as $name => [$policies, $specification, $extent]) {
            if (Allocator::resolve($policies, $extent, $specification)->overflow === 0) {
                yield $name => [$policies, $specification, $extent];
            }
        }
    }

    /**
     * @return iterable<string, array{list<ItemPolicy>, AxisSpecification, int}>
     */
    public static function compressedCasesWhereEveryFloorFitsAndEverySlotYields(): iterable
    {
        foreach (AllocatorSweep::casesWhereEveryFloorFitsAndEverySlotYields() as $name => [$policies, $specification, $extent]) {
            if ($specification->overflow === Overflow::Compress) {
                yield $name => [$policies, $specification, $extent];
            }
        }
    }

    /**
     * @return iterable<string, array{list<ItemPolicy>, AxisSpecification, int}>
     */
    public static function squeezedCases(): iterable
    {
        return AllocatorSweep::casesUnder(Overflow::SqueezeToFloor);
    }

    /**
     * @return iterable<string, array{list<ItemPolicy>, AxisSpecification, int}>
     */
    public static function clippedCases(): iterable
    {
        return AllocatorSweep::casesUnder(Overflow::ClipTail);
    }
}
