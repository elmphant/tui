<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Layout;

use Elmphant\Tui\Layout\Rect;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RectTest extends TestCase
{
    #[Test]
    public function fromSize(): void
    {
        $rect = Rect::fromSize(80, 24);
        self::assertSame(0, $rect->x);
        self::assertSame(0, $rect->y);
        self::assertSame(80, $rect->width);
        self::assertSame(24, $rect->height);
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function negativeExtentProvider(): iterable
    {
        yield 'negative width' => [-1, 10];
        yield 'negative height' => [10, -1];
    }

    #[Test]
    #[DataProvider('negativeExtentProvider')]
    public function anExtentBelowZeroIsRefused(int $width, int $height): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Rect extent must be non-negative');

        new Rect(0, 0, $width, $height);
    }

    #[Test]
    public function contains(): void
    {
        $rect = new Rect(5, 5, 10, 10);
        self::assertTrue($rect->contains(5, 5));
        self::assertTrue($rect->contains(14, 14));
        self::assertFalse($rect->contains(4, 5));
        self::assertFalse($rect->contains(15, 5));
    }

    #[Test]
    public function equalityComparesEveryCoordinateAndDimension(): void
    {
        $rect = new Rect(1, 2, 3, 4);

        self::assertTrue($rect->equals(new Rect(1, 2, 3, 4)));
        self::assertFalse($rect->equals(new Rect(9, 2, 3, 4)));
        self::assertFalse($rect->equals(new Rect(1, 9, 3, 4)));
        self::assertFalse($rect->equals(new Rect(1, 2, 9, 4)));
        self::assertFalse($rect->equals(new Rect(1, 2, 3, 9)));
    }

    #[Test]
    public function bottom(): void
    {
        $rect = new Rect(0, 5, 10, 10);
        self::assertSame(15, $rect->bottom());
    }

    #[Test]
    public function right(): void
    {
        $rect = new Rect(5, 0, 10, 10);
        self::assertSame(15, $rect->right());
    }

    #[Test]
    public function shrink(): void
    {
        $rect = new Rect(0, 0, 20, 10);
        $inner = $rect->shrink(1, 2, 1, 2);
        self::assertSame(2, $inner->x);
        self::assertSame(1, $inner->y);
        self::assertSame(16, $inner->width);
        self::assertSame(8, $inner->height);
    }

    #[Test]
    public function inner(): void
    {
        $rect = new Rect(0, 0, 20, 10);
        $inner = $rect->inner(1);
        self::assertSame(1, $inner->x);
        self::assertSame(1, $inner->y);
        self::assertSame(18, $inner->width);
        self::assertSame(8, $inner->height);
    }

    #[Test]
    public function shrinkBeyondDimensionsCollapsesToZero(): void
    {
        $rect = Rect::fromSize(10, 10);
        $collapsed = $rect->shrink(20, 20, 20, 20);
        self::assertSame(0, $collapsed->width);
        self::assertSame(0, $collapsed->height);
    }

    #[Test]
    public function containsOnEdges(): void
    {
        $rect = new Rect(5, 5, 10, 10);
        self::assertTrue($rect->contains(5, 5), 'Top-left corner is inside');
        self::assertFalse($rect->contains(15, 15), 'Bottom-right edge is outside (exclusive)');
        self::assertTrue($rect->contains(14, 14), 'One before bottom-right is inside');
        self::assertFalse($rect->contains(4, 5), 'Left of rect is outside');
    }

    #[Test]
    public function zeroSizeRectContainsNothing(): void
    {
        $rect = new Rect(5, 5, 0, 0);
        self::assertFalse($rect->contains(5, 5));
    }

    #[Test]
    public function containsExcludesBottomBoundary(): void
    {
        $rect = new Rect(5, 5, 10, 10);

        self::assertTrue($rect->contains(10, 14), 'Y one above bottom is inside');
        self::assertFalse($rect->contains(10, 15), 'Y at bottom boundary is outside (exclusive)');
    }

    #[Test]
    public function innerOnSmallRect(): void
    {
        $rect = new Rect(0, 0, 2, 2);
        $inner = $rect->inner();
        self::assertSame(0, $inner->width);
        self::assertSame(0, $inner->height);
    }

    #[Test]
    public function aPointIsFoundInTheFirstRectThatHoldsIt(): void
    {
        $rects = [new Rect(0, 0, 5, 1), new Rect(5, 0, 5, 1), new Rect(5, 0, 10, 1)];

        self::assertSame(1, Rect::findContaining($rects, 7, 0));
        self::assertNull(Rect::findContaining($rects, 20, 0));
    }
}
