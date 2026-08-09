<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Layout\Invariant;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Direction;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\SplitState;
use Elmphant\Tui\Widget\Split;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SplitAllocationInvariantTest extends TestCase
{
    use RectInvariants;

    private const DIVIDER = 1;

    private const EXTENTS = [0, 1, 2, 3, 7, 20, 80];

    #[Test]
    #[DataProvider('splits')]
    public function panesNeverOverlap(array $shares, array $minimums, int $extent): void
    {
        self::assertNoneOverlap($this->paneRects($shares, $minimums, $extent));
    }

    #[Test]
    #[DataProvider('splits')]
    public function oneColumnOfDividerSitsBetweenEveryPair(array $shares, array $minimums, int $extent): void
    {
        $area = self::areaOf($extent);

        self::assertAdjacentAlong($this->paneRects($shares, $minimums, $extent), $area, Direction::Horizontal, self::DIVIDER);
    }

    #[Test]
    #[DataProvider('splitsWideEnoughForTheirDividers')]
    public function everyPaneStaysInsideTheArea(array $shares, array $minimums, int $extent): void
    {
        $area = self::areaOf($extent);

        self::assertAllWithin($this->paneRects($shares, $minimums, $extent), $area);
    }

    #[Test]
    #[DataProvider('splitsNarrowerThanTheirDividers')]
    public function panesAreLaidOutPastTheAreaWhenTheDividersAloneDoNotFit(array $shares, array $minimums, int $extent): void
    {
        $rects = $this->paneRects($shares, $minimums, $extent);
        $last = $rects[count($rects) - 1];

        self::assertSame(0, $last->width);
        self::assertSame(count($shares) - 1, $last->x - self::areaOf($extent)->x);
        self::assertGreaterThan(self::areaOf($extent)->right(), $last->right());
    }

    #[Test]
    #[DataProvider('splitsWideEnoughForTheirDividers')]
    public function thePanesAndDividersFillTheAxis(array $shares, array $minimums, int $extent): void
    {
        $rects = $this->paneRects($shares, $minimums, $extent);
        $widths = array_sum(self::extentsAlong($rects, Direction::Horizontal));

        self::assertSame($extent, $widths + self::DIVIDER * (count($rects) - 1));
    }

    #[Test]
    #[DataProvider('splitsThatHoldEveryMinimum')]
    public function noPaneIsSqueezedBelowItsMinimum(array $shares, array $minimums, int $extent): void
    {
        $widths = self::extentsAlong($this->paneRects($shares, $minimums, $extent), Direction::Horizontal);

        foreach ($widths as $index => $width) {
            self::assertGreaterThanOrEqual(max(1, $minimums[$index] ?? 1), $width);
        }
    }

    #[Test]
    #[DataProvider('splits')]
    public function theSameSplitResolvesTheSameWayTwice(array $shares, array $minimums, int $extent): void
    {
        self::assertEquals(
            $this->paneRects($shares, $minimums, $extent),
            $this->paneRects($shares, $minimums, $extent),
        );
    }

    #[Test]
    #[DataProvider('splits')]
    public function aColumnDividesItsHeightLikeARowDividesItsWidth(array $shares, array $minimums, int $extent): void
    {
        $row = Split::row(new SplitState($shares), ...$this->children($shares))->withMinimums(...$minimums);
        $column = Split::column(new SplitState($shares), ...$this->children($shares))->withMinimums(...$minimums);

        self::assertSame(
            self::extentsAlong(self::rectsOf($row->childWidgets(new Rect(0, 0, $extent, 5))), Direction::Horizontal),
            self::extentsAlong(self::rectsOf($column->childWidgets(new Rect(0, 0, 5, $extent))), Direction::Vertical),
        );
    }

    /**
     * @return iterable<string, array{list<int>, list<int>, int}>
     */
    public static function splits(): iterable
    {
        $shapes = [
            'even pair' => [[1, 1], []],
            'lopsided pair' => [[1, 2], []],
            'even trio' => [[1, 1, 1], []],
            'leading trio' => [[3, 1, 1], []],
            'permille pair' => [[500, 500], []],
            'quartet' => [[1, 1, 1, 1], []],
            'pair with floors' => [[1, 1], [5, 5]],
            'trio with floors' => [[1, 1, 1], [10, 3, 3]],
        ];

        foreach ($shapes as $name => [$shares, $minimums]) {
            foreach (self::EXTENTS as $extent) {
                yield $name . ' in ' . $extent => [$shares, $minimums, $extent];
            }
        }
    }

    /**
     * @return iterable<string, array{list<int>, list<int>, int}>
     */
    public static function splitsWideEnoughForTheirDividers(): iterable
    {
        foreach (self::splits() as $name => [$shares, $minimums, $extent]) {
            if ($extent >= self::DIVIDER * (count($shares) - 1)) {
                yield $name => [$shares, $minimums, $extent];
            }
        }
    }

    /**
     * @return iterable<string, array{list<int>, list<int>, int}>
     */
    public static function splitsNarrowerThanTheirDividers(): iterable
    {
        foreach (self::splits() as $name => [$shares, $minimums, $extent]) {
            if ($extent < self::DIVIDER * (count($shares) - 1)) {
                yield $name => [$shares, $minimums, $extent];
            }
        }
    }

    /**
     * @return iterable<string, array{list<int>, list<int>, int}>
     */
    public static function splitsThatHoldEveryMinimum(): iterable
    {
        foreach (self::splits() as $name => [$shares, $minimums, $extent]) {
            $needed = self::DIVIDER * (count($shares) - 1);
            foreach ($shares as $index => $ignored) {
                $needed += max(1, $minimums[$index] ?? 1);
            }

            if ($needed <= $extent) {
                yield $name => [$shares, $minimums, $extent];
            }
        }
    }

    private static function areaOf(int $extent): Rect
    {
        return new Rect(4, 2, $extent, 5);
    }

    /**
     * @param list<int> $shares
     * @param list<int> $minimums
     *
     * @return list<Rect>
     */
    private function paneRects(array $shares, array $minimums, int $extent): array
    {
        $split = Split::row(new SplitState($shares), ...$this->children($shares))->withMinimums(...$minimums);

        return self::rectsOf($split->childWidgets(self::areaOf($extent)));
    }

    /**
     * @param list<int> $shares
     *
     * @return list<ConstrainedWidget>
     */
    private function children(array $shares): array
    {
        return array_map(static fn (int $ignored): ConstrainedWidget => new ConstrainedWidget(Constraint::flex()), $shares);
    }
}
