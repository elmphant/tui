<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Layout;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Spacing;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SpacingTest extends TestCase
{
    #[Test]
    public function noneCreatesAllZeros(): void
    {
        $spacing = Spacing::none();

        self::assertSame(0, $spacing->top);
        self::assertSame(0, $spacing->right);
        self::assertSame(0, $spacing->bottom);
        self::assertSame(0, $spacing->left);
    }

    #[Test]
    public function allWithValueThreeProducesThreeOnAllSides(): void
    {
        $spacing = Spacing::all(3);

        self::assertSame(3, $spacing->top);
        self::assertSame(3, $spacing->right);
        self::assertSame(3, $spacing->bottom);
        self::assertSame(3, $spacing->left);
    }

    #[Test]
    public function verticalWithValueFiveProducesFiveForTopAndBottom(): void
    {
        $spacing = Spacing::vertical(5);

        self::assertSame(5, $spacing->top);
        self::assertSame(0, $spacing->right);
        self::assertSame(5, $spacing->bottom);
        self::assertSame(0, $spacing->left);
    }

    #[Test]
    public function horizontalWithValueFourProducesFourForLeftAndRight(): void
    {
        $spacing = Spacing::horizontal(4);

        self::assertSame(0, $spacing->top);
        self::assertSame(4, $spacing->right);
        self::assertSame(0, $spacing->bottom);
        self::assertSame(4, $spacing->left);
    }

    #[Test]
    public function xyUsesHorizontalAndVerticalValues(): void
    {
        $spacing = Spacing::xy(horizontal: 4, vertical: 2);

        self::assertSame(2, $spacing->top);
        self::assertSame(4, $spacing->right);
        self::assertSame(2, $spacing->bottom);
        self::assertSame(4, $spacing->left);
    }

    #[Test]
    public function onlySupportsReadableNamedSides(): void
    {
        $spacing = Spacing::only(top: 1, right: 2, bottom: 3, left: 4);

        self::assertSame(1, $spacing->top);
        self::assertSame(2, $spacing->right);
        self::assertSame(3, $spacing->bottom);
        self::assertSame(4, $spacing->left);
    }

    #[Test]
    public function onlyDefaultsEveryUnspecifiedSideToZero(): void
    {
        $spacing = Spacing::only();

        self::assertSame(0, $spacing->top);
        self::assertSame(0, $spacing->right);
        self::assertSame(0, $spacing->bottom);
        self::assertSame(0, $spacing->left);
    }

    #[Test]
    public function valuesAboveTheSupportedRangeAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Spacing must be between 0 and 255, got 300');

        Spacing::all(300);
    }

    #[Test]
    public function maximumSupportedSpacingIsAccepted(): void
    {
        self::assertSame(255, Spacing::all(255)->top);
    }

    #[Test]
    public function negativeValuesAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Spacing must be between 0 and 255, got -5');

        Spacing::all(-5);
    }

    #[Test]
    public function applyToShrinksRect(): void
    {
        $spacing = Spacing::all(1);
        $rect = new Rect(0, 0, 20, 10);

        $result = $spacing->applyTo($rect);

        self::assertSame(1, $result->x);
        self::assertSame(1, $result->y);
        self::assertSame(18, $result->width);
        self::assertSame(8, $result->height);
    }

    #[Test]
    public function applyToWithVerticalSpacing(): void
    {
        $spacing = Spacing::vertical(1);
        $rect = new Rect(0, 0, 20, 10);

        $result = $spacing->applyTo($rect);

        self::assertSame(0, $result->x);
        self::assertSame(1, $result->y);
        self::assertSame(20, $result->width);
        self::assertSame(8, $result->height);
    }

    #[Test]
    public function applyToWithHorizontalSpacing(): void
    {
        $spacing = Spacing::horizontal(1);
        $rect = new Rect(0, 0, 20, 10);

        $result = $spacing->applyTo($rect);

        self::assertSame(1, $result->x);
        self::assertSame(0, $result->y);
        self::assertSame(18, $result->width);
        self::assertSame(10, $result->height);
    }

    #[Test]
    public function noneApplyToReturnsIdenticalRect(): void
    {
        $spacing = Spacing::none();
        $rect = new Rect(5, 3, 20, 10);

        $result = $spacing->applyTo($rect);

        self::assertSame(5, $result->x);
        self::assertSame(3, $result->y);
        self::assertSame(20, $result->width);
        self::assertSame(10, $result->height);
    }
}
