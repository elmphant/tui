<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Layout\Axis;

use Elmphant\Tui\Layout\Axis\Allocator;
use Elmphant\Tui\Layout\Axis\AxisSpecification;
use Elmphant\Tui\Layout\Axis\ItemPolicy;
use Elmphant\Tui\Layout\Axis\Justification;
use Elmphant\Tui\Layout\Axis\Overflow;
use Elmphant\Tui\Layout\Axis\Remainder;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Tests\Layout\Invariant\ConstraintSweep;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AllocatorTest extends TestCase
{
    /**
     * @param list<Constraint> $constraints
     */
    #[Test]
    #[DataProvider('cases')]
    public function sizesEverySweptRowTheWayConstraintsAlreadyDo(array $constraints, int $extent): void
    {
        $policies = array_map(static fn (Constraint $constraint) => $constraint->policy(), $constraints);

        self::assertSame(
            Constraint::allocate($constraints, $extent),
            Allocator::resolve($policies, $extent)->sizes,
        );
    }

    #[Test]
    public function theCellsNobodyClaimedSitBeforeTheSlotsWhenTheyArePushedToTheEnd(): void
    {
        $policies = [new ItemPolicy(basis: 3), new ItemPolicy(basis: 3)];

        $resolved = Allocator::resolve($policies, 10, new AxisSpecification(justification: Justification::End));

        self::assertSame([3, 3], $resolved->sizes, 'Nothing grows, so the spare cells stay unclaimed');
        self::assertSame([4, 7], $resolved->offsets);
    }

    #[Test]
    public function centringLeavesTheOddCellAfterTheSlots(): void
    {
        $policies = [new ItemPolicy(basis: 3), new ItemPolicy(basis: 3)];

        $resolved = Allocator::resolve($policies, 11, new AxisSpecification(justification: Justification::Center));

        self::assertSame([2, 5], $resolved->offsets, 'Five cells spare puts two before the slots and three after');
    }

    #[Test]
    public function spacingBetweenPushesTheOutermostSlotsToTheEdges(): void
    {
        $policies = [new ItemPolicy(basis: 2), new ItemPolicy(basis: 2), new ItemPolicy(basis: 2)];

        $resolved = Allocator::resolve($policies, 13, new AxisSpecification(justification: Justification::SpaceBetween));

        self::assertSame([0, 5, 11], $resolved->offsets, 'Seven spare cells over two openings is three then four, so the last slot still ends at the edge');
    }

    #[Test]
    public function spacingEvenlyLeavesAsMuchRoomAtTheEdgesAsBetweenTheSlots(): void
    {
        $policies = [new ItemPolicy(basis: 2), new ItemPolicy(basis: 2)];

        $resolved = Allocator::resolve($policies, 14, new AxisSpecification(justification: Justification::SpaceEvenly));

        self::assertSame([3, 8], $resolved->offsets, 'Ten cells over three openings is three, three, then four, so the run still ends at the edge');
    }

    #[Test]
    public function clippingTheTailCutsTheLastSlotsRatherThanNarrowingThemAll(): void
    {
        $policies = [new ItemPolicy(basis: 6), new ItemPolicy(basis: 6)];

        $resolved = Allocator::resolve($policies, 8, new AxisSpecification(overflow: Overflow::ClipTail));

        self::assertSame([6, 2], $resolved->sizes);
    }

    #[Test]
    public function retainingKeepsEverySizeAndReportsTheOverrun(): void
    {
        $policies = [new ItemPolicy(basis: 6, shrink: 1), new ItemPolicy(basis: 6, shrink: 1)];

        $resolved = Allocator::resolve($policies, 8, new AxisSpecification(overflow: Overflow::Retain));

        self::assertSame([6, 6], $resolved->sizes, 'A slot willing to give way is not asked to');
        self::assertSame(4, $resolved->overflow);
    }

    #[Test]
    public function squeezingTakesTheFloorsThemselvesWhenEvenTheyDoNotFit(): void
    {
        $policies = [new ItemPolicy(basis: 6, min: 6), new ItemPolicy(basis: 6, min: 6)];

        $resolved = Allocator::resolve($policies, 8, new AxisSpecification(overflow: Overflow::SqueezeToFloor));

        self::assertSame([4, 4], $resolved->sizes);
        self::assertSame(0, $resolved->overflow, 'Nothing is left outside the axis');
    }

    #[Test]
    public function aSlotStartsAtItsFloorEvenWhenItAsksForLess(): void
    {
        $policies = [new ItemPolicy(basis: 0, min: 10, grow: 1)];

        self::assertSame([10], Allocator::resolve($policies, 4)->sizes, 'A floor of ten cannot be honoured in four cells, and is not quietly dropped');
    }

    #[Test]
    public function aGapWiderThanTheAxisGivesWayToWhatIsThere(): void
    {
        $policies = [new ItemPolicy(basis: 2), new ItemPolicy(basis: 2), new ItemPolicy(basis: 2)];

        $resolved = Allocator::resolve($policies, 4, new AxisSpecification(gap: 5, overflow: Overflow::SqueezeToFloor));

        self::assertSame([0, 2, 4], $resolved->offsets, 'Two gap columns is all four cells can carry between three slots');

        foreach ($resolved->offsets as $index => $offset) {
            self::assertLessThanOrEqual(4, $offset + $resolved->sizes[$index], sprintf('Slot %d ends outside the axis', $index));
        }
    }

    #[Test]
    public function whatACappedSlotCannotTakeIsSharedByTheRest(): void
    {
        $policies = [
            new ItemPolicy(basis: 0, max: 4, grow: 1),
            new ItemPolicy(basis: 0, grow: 1),
            new ItemPolicy(basis: 0, grow: 1),
        ];

        self::assertSame([4, 8, 8], Allocator::resolve($policies, 20)->sizes, 'The two uncapped slots are identical, so they end the same size');
    }

    #[Test]
    public function whatAFlooredSlotCannotGiveIsSharedByTheRest(): void
    {
        $policies = [
            new ItemPolicy(basis: 10, min: 8, shrink: 1),
            new ItemPolicy(basis: 10, shrink: 1),
            new ItemPolicy(basis: 10, shrink: 1),
        ];

        self::assertSame([8, 4, 3], Allocator::resolve($policies, 15)->sizes, 'The cells the first slot could not give are split between the two that can');
    }

    #[Test]
    public function squeezingNeverHandsACellToASlotThatWillNotHoldOne(): void
    {
        $policies = [
            new ItemPolicy(basis: 0, min: 0, max: 0),
            new ItemPolicy(basis: 10, min: 1, shrink: 1),
            new ItemPolicy(basis: 10, min: 1, shrink: 1),
        ];

        $resolved = Allocator::resolve($policies, 1, new AxisSpecification(overflow: Overflow::SqueezeToFloor));

        self::assertSame(0, $resolved->sizes[0], 'A slot whose ceiling is zero stays at zero however scarce the axis is');
    }

    #[Test]
    public function aWideSlotGivesUpMoreThanANarrowOneOfTheSameWillingness(): void
    {
        $policies = [new ItemPolicy(basis: 10, shrink: 1), new ItemPolicy(basis: 2, shrink: 1)];

        self::assertSame([5, 1], Allocator::resolve($policies, 6)->sizes, 'Willingness is weighed against what a slot holds, so the wide one carries most of the loss');
    }

    #[Test]
    public function theCellsNoShareDividesGoOneEachToTheRearmostSlots(): void
    {
        $policies = array_fill(0, 12, new ItemPolicy(grow: 1));

        self::assertSame(
            [0, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1],
            Allocator::resolve($policies, 11)->sizes,
            'Eleven cells over twelve equal slots is one each for all but the foremost, never eleven for the last',
        );
    }

    #[Test]
    public function theCellsNoShareDividesCanGoToTheStrongestClaimsInstead(): void
    {
        $policies = array_fill(0, 12, new ItemPolicy(grow: 1));

        self::assertSame(
            [1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 0],
            Allocator::resolve($policies, 11, new AxisSpecification(remainder: Remainder::StrongestClaim))->sizes,
            'Twelve equal claims on eleven cells: one each from the front, and the last goes without',
        );
    }

    #[Test]
    public function sizesBeyondCountingAreStillFittedToTheAxis(): void
    {
        $policies = [new ItemPolicy(basis: PHP_INT_MAX), new ItemPolicy(basis: PHP_INT_MAX)];

        $resolved = Allocator::resolve($policies, 10, new AxisSpecification(overflow: Overflow::SqueezeToFloor));

        self::assertSame([5, 5], $resolved->sizes, 'Two slots asking for more than can be counted still divide ten columns evenly');
    }

    #[Test]
    public function squeezingPastTheFloorsStillHeedsHowWillingEachSlotWas(): void
    {
        $policies = [
            new ItemPolicy(basis: 8, min: 8, shrink: 1),
            new ItemPolicy(basis: 4, min: 4, shrink: 10),
        ];

        $resolved = Allocator::resolve($policies, 9, new AxisSpecification(overflow: Overflow::SqueezeToFloor));

        self::assertSame([8, 1], $resolved->sizes, 'Both sit at their floors, and the one that said it was ten times more willing gives up the columns');
    }

    #[Test]
    public function slotsThatMayGiveWayGiveUpCellsByTheirWeight(): void
    {
        $wide = new ItemPolicy(basis: 6, shrink: 1);
        $stubborn = new ItemPolicy(basis: 6);

        self::assertSame([4, 4], Allocator::resolve([$wide, new ItemPolicy(basis: 6, shrink: 1)], 8)->sizes);
        self::assertSame([2, 6], Allocator::resolve([$wide, $stubborn], 8)->sizes, 'A slot with no shrink weight keeps what it asked for');
    }

    #[Test]
    public function aSlotGivingWayStopsAtItsFloorAndTheRestIsTakenElsewhere(): void
    {
        $policies = [new ItemPolicy(basis: 6, min: 5, shrink: 1), new ItemPolicy(basis: 6, shrink: 1)];

        self::assertSame([5, 3], Allocator::resolve($policies, 8)->sizes);
    }

    #[Test]
    public function aGapTakesItsCellsFromWhatTheSlotsHaveToShare(): void
    {
        $policies = array_map(
            static fn (Constraint $constraint) => $constraint->policy(),
            [Constraint::flex(), Constraint::flex()],
        );

        $resolved = Allocator::resolve($policies, 11, new AxisSpecification(gap: 1));

        self::assertSame([5, 5], $resolved->sizes, 'The gap column is not one of theirs to take');
        self::assertSame([0, 6], $resolved->offsets, 'So the second slot starts one column further along');
    }

    #[Test]
    public function eachSlotStartsWhereThePreviousOneEnds(): void
    {
        $policies = array_map(
            static fn (Constraint $constraint) => $constraint->policy(),
            [Constraint::exact(3), Constraint::flex(), Constraint::exact(2)],
        );

        $resolved = Allocator::resolve($policies, 10);

        self::assertSame([3, 5, 2], $resolved->sizes);
        self::assertSame([0, 3, 8], $resolved->offsets);
    }

    /**
     * @return iterable<string, array{list<Constraint>, int}>
     */
    public static function cases(): iterable
    {
        return ConstraintSweep::cases();
    }
}
