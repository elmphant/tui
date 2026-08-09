<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Layout\Axis;

use Elmphant\Tui\Layout\Axis\Allocator;
use Elmphant\Tui\Layout\Axis\AxisSpec;
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
    public function aGapTakesItsCellsFromWhatTheSlotsHaveToShare(): void
    {
        $policies = array_map(
            static fn (Constraint $constraint) => $constraint->policy(),
            [Constraint::flex(), Constraint::flex()],
        );

        $resolved = Allocator::resolve($policies, 11, new AxisSpec(gap: 1));

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
