<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Layout;

use Elmphant\Tui\Layout\Constraint;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConstraintTest extends TestCase
{
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
    public function withFlexKeepsTheBoundsAndDefaultsToWeightOne(): void
    {
        $constraint = Constraint::min(3)->withFlex();

        self::assertSame(3, $constraint->min);
        self::assertSame(PHP_INT_MAX, $constraint->max);
        self::assertSame(1, $constraint->flex);
    }

    #[Test]
    public function withFlexTakesAnExplicitWeight(): void
    {
        self::assertSame(4, Constraint::min(3)->withFlex(4)->flex);
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

        new Constraint(min: 8, max: 2);
    }
}
