<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Layout;

use Elmphant\Tui\Layout\Axis\Allocator;
use Elmphant\Tui\Layout\Constraint;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConstraintTest extends TestCase
{
    #[Test]
    public function allocationReservesEveryFloorThenSharesTheRestByWeight(): void
    {
        $sizes = self::shared([Constraint::min(5), Constraint::flex(), Constraint::flex(3)], 25);

        self::assertSame([5, 5, 15], $sizes);
    }

    #[Test]
    public function exact(): void
    {
        $constraint = Constraint::exact(5);
        self::assertSame(5, $constraint->min);
        self::assertSame(5, $constraint->max);
        self::assertSame(0, $constraint->flex);
    }

    #[Test]
    public function min(): void
    {
        $constraint = Constraint::min(3);
        self::assertSame(3, $constraint->min);
        self::assertSame(PHP_INT_MAX, $constraint->max);
        self::assertSame(0, $constraint->flex);
    }

    #[Test]
    public function flex(): void
    {
        $constraint = Constraint::flex(2);
        self::assertSame(0, $constraint->min);
        self::assertSame(PHP_INT_MAX, $constraint->max);
        self::assertSame(2, $constraint->flex);
    }

    #[Test]
    public function flexDefaultWeight(): void
    {
        $constraint = Constraint::flex();
        self::assertSame(1, $constraint->flex);
    }

    #[Test]
    public function maxGrowsUpToItsCeiling(): void
    {
        $constraint = Constraint::max(5);

        self::assertSame(0, $constraint->min);
        self::assertSame(5, $constraint->max);
        self::assertSame(1, $constraint->flex, 'A ceiling is useless without the growth that reaches it');
    }

    #[Test]
    public function aStartingSizeAndAGivingWeightReachThePolicy(): void
    {
        $policy = Constraint::min(2)->withMax(8)->withFlex()->withBasis(5)->withShrink(3)->policy();

        self::assertSame(5, $policy->basis, 'The declared starting size is where the slot begins');
        self::assertSame(3, $policy->shrink, 'and this is how readily it gives that size back');
    }

    #[Test]
    public function aSlotGivesBackTheStartingSizeItCannotKeep(): void
    {
        $constraints = [Constraint::min(0)->withBasis(8), Constraint::exact(2)];

        self::assertSame([2, 2], self::shared($constraints, 4));
    }

    #[Test]
    public function withFlexKeepsTheBoundsAndDefaultsToWeightOne(): void
    {
        $constraint = Constraint::min(3)->withFlex();

        self::assertSame(3, $constraint->min);
        self::assertSame(PHP_INT_MAX, $constraint->max);
        self::assertSame(1, $constraint->flex);
    }

    #[Test]
    public function withFlexKeepsTheStartingSizeAndTheGivingWeight(): void
    {
        $constraint = Constraint::min(2)->withMax(40)->withFlex()->withBasis(12)->withShrink(0)->withFlex(3);

        self::assertSame(12, $constraint->basis);
        self::assertSame(0, $constraint->shrink);
    }

    #[Test]
    public function withFlexTakesAnExplicitWeight(): void
    {
        self::assertSame(4, Constraint::min(3)->withFlex(4)->flex);
    }

    #[Test]
    public function withBasisDeclaresTheStartingSizeAndKeepsTheRest(): void
    {
        $constraint = Constraint::min(2)->withMax(40)->withFlex(3)->withShrink(0)->withBasis(12);

        self::assertSame(12, $constraint->basis);
        self::assertSame(2, $constraint->min);
        self::assertSame(40, $constraint->max);
        self::assertSame(3, $constraint->flex);
        self::assertSame(0, $constraint->shrink);
    }

    #[Test]
    public function withMinRaisesTheFloorAndKeepsTheRest(): void
    {
        $constraint = Constraint::flex(3)->withBasis(12)->withMin(4);

        self::assertSame([4, PHP_INT_MAX, 3, 12, 1], self::fieldsOf($constraint));
    }

    #[Test]
    public function withMaxSetsTheCeilingAndKeepsTheRest(): void
    {
        $constraint = Constraint::min(2)->withFlex(3)->withMax(40);

        self::assertSame([2, 40, 3, null, 1], self::fieldsOf($constraint));
    }

    #[Test]
    public function withShrinkSetsTheGivingWeightAndKeepsTheRest(): void
    {
        $constraint = Constraint::min(2)->withBasis(12)->withShrink(0);

        self::assertSame([2, PHP_INT_MAX, 0, 12, 0], self::fieldsOf($constraint));
    }

    #[Test]
    public function aBuilderKeepsAContentConstraintMeasuringItsContent(): void
    {
        self::assertTrue(Constraint::content()->withMin(2)->withMax(9)->withShrink(0)->measuresContent);
    }

    #[Test]
    public function theIntrinsicSizeIsTheDeclaredBasis(): void
    {
        self::assertSame(12, Constraint::flex()->withBasis(12)->intrinsic());
    }

    #[Test]
    public function withoutABasisTheIntrinsicSizeIsTheFloor(): void
    {
        self::assertSame(3, Constraint::min(3)->withFlex(2)->intrinsic());
    }

    #[Test]
    public function exactWithZero(): void
    {
        $constraint = Constraint::exact(0);
        self::assertSame(0, $constraint->min);
        self::assertSame(0, $constraint->max);
    }

    #[Test]
    public function flexWithCustomWeight(): void
    {
        $constraint = Constraint::flex(3);
        self::assertSame(3, $constraint->flex);
        self::assertSame(0, $constraint->min);
    }

    #[Test]
    public function commonImmutableConstraintsAreReused(): void
    {
        self::assertSame(Constraint::exact(0), Constraint::exact(0));
        self::assertSame(Constraint::exact(1), Constraint::exact(1));
        self::assertSame(Constraint::flex(), Constraint::flex(1));
    }

    #[Test]
    public function uncommonConstraintValuesRemainIndependent(): void
    {
        self::assertNotSame(Constraint::exact(2), Constraint::exact(2));
        self::assertNotSame(Constraint::flex(2), Constraint::flex(2));
    }

    #[Test]
    public function aCeilingBelowTheFloorIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Constraint max must be greater than or equal to min');

        Constraint::min(8)->withMax(2);
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function negativeBoundsProvider(): iterable
    {
        yield 'floor below zero' => [-1, PHP_INT_MAX];
        yield 'ceiling below zero' => [-5, -1];
    }

    #[Test]
    #[DataProvider('negativeBoundsProvider')]
    public function aBoundBelowZeroIsRefused(int $min, int $max): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Constraint bounds must be non-negative');

        Constraint::min($min)->withMax($max);
    }

    #[Test]
    public function aWeightTooLargeToShareOutIsRefused(): void
    {
        $refused = [];
        foreach ([static fn () => Constraint::flex(1_000_001), static fn () => Constraint::min(0)->withShrink(PHP_INT_MAX)] as $build) {
            try {
                $build();
            } catch (InvalidArgumentException $error) {
                $refused[] = $error->getMessage();
            }
        }

        self::assertSame([
            'Constraint weights go up to 1000000, got flex 1000001 and shrink 1',
            'Constraint weights go up to 1000000, got flex 0 and shrink ' . PHP_INT_MAX,
        ], $refused);
    }

    #[Test]
    public function aWeightOfExactlyAMillionIsAccepted(): void
    {
        $constraint = Constraint::flex(1_000_000)->withShrink(1_000_000);

        self::assertSame([1_000_000, 1_000_000], [$constraint->flex, $constraint->shrink]);
    }

    #[Test]
    public function growingOnNoWeightIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Constraint::flex() needs a weight above zero');

        Constraint::flex(0);
    }

    #[Test]
    public function aGrowthWeightBelowZeroIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Constraint flex weight must be non-negative');

        Constraint::min(0)->withFlex(-1);
    }

    /**
     * @param list<Constraint> $constraints
     *
     * @return list<int>
     */
    private static function shared(array $constraints, int $available): array
    {
        return Allocator::resolve(array_map(static fn (Constraint $constraint) => $constraint->policy(), $constraints), $available)->sizes;
    }

    /**
     * @return array{int, int, int, ?int, int}
     */
    private static function fieldsOf(Constraint $constraint): array
    {
        return [$constraint->min, $constraint->max, $constraint->flex, $constraint->basis, $constraint->shrink];
    }
}
