<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Layout\Invariant;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\KeyedCache;
use Elmphant\Tui\Layout\VerticalStack;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class VerticalStackAllocationInvariantTest extends TestCase
{
    #[Test]
    #[DataProviderExternal(ConstraintSweep::class, 'cases')]
    public function noChildIsShorterThanItsFloorOrTallerThanItsCeiling(array $constraints, int $extent): void
    {
        $heights = $this->heightsOf($constraints, $extent);

        foreach ($constraints as $index => $constraint) {
            self::assertGreaterThanOrEqual($constraint->min, $heights[$index]);
            self::assertLessThanOrEqual($constraint->max, $heights[$index]);
        }
    }

    #[Test]
    #[DataProviderExternal(ConstraintSweep::class, 'cases')]
    public function theReportedTotalIsTheSumOfTheHeights(array $constraints, int $extent): void
    {
        $layout = VerticalStack::layoutUsing(new KeyedCache(), $this->widgetsFor($constraints), 40, $extent);

        self::assertSame(array_sum($layout->heights), $layout->totalHeight);
    }

    #[Test]
    #[DataProviderExternal(ConstraintSweep::class, 'cases')]
    public function aScrollbarAppearsExactlyWhenTheChildrenOutgrowTheViewport(array $constraints, int $extent): void
    {
        $layout = VerticalStack::layoutUsing(new KeyedCache(), $this->widgetsFor($constraints), 40, $extent);

        self::assertSame($layout->totalHeight > $extent, $layout->hasScrollbar);
    }

    #[Test]
    #[DataProvider('casesTheStackFillsExactly')]
    public function theStackLeavesNoRowUnused(array $constraints, int $extent): void
    {
        self::assertSame($extent, array_sum($this->heightsOf($constraints, $extent)));
    }

    #[Test]
    #[DataProviderExternal(ConstraintSweep::class, 'cases')]
    public function theSameStackResolvesTheSameWayTwice(array $constraints, int $extent): void
    {
        self::assertSame($this->heightsOf($constraints, $extent), $this->heightsOf($constraints, $extent));

        $shared = new KeyedCache();
        $children = $this->widgetsFor($constraints);

        self::assertSame(
            VerticalStack::layoutUsing($shared, $children, 40, $extent)->heights,
            VerticalStack::layoutUsing($shared, $children, 40, $extent)->heights,
        );
    }

    #[Test]
    public function aSharedCacheTellsTwoChildListsApart(): void
    {
        $shared = new KeyedCache();
        $first = $this->widgetsFor([Constraint::exact(2), Constraint::flex()]);
        $second = $this->widgetsFor([Constraint::exact(5), Constraint::flex()]);

        self::assertSame([2, 8], VerticalStack::layoutUsing($shared, $first, 40, 10)->heights);
        self::assertSame([5, 5], VerticalStack::layoutUsing($shared, $second, 40, 10)->heights);
        self::assertSame([2, 8], VerticalStack::layoutUsing($shared, $first, 40, 10)->heights);
    }

    #[Test]
    public function everySweptStackTakesTheHeightsRecordedForIt(): void
    {
        $resolved = [];

        foreach (ConstraintSweep::cases() as $name => [$constraints, $extent]) {
            $resolved[$name] = $this->heightsOf($constraints, $extent);
        }

        self::assertSame(AllocationBaseline::verticalStacks(), $resolved);
    }

    /**
     * @return iterable<string, array{list<Constraint>, int}>
     */
    public static function casesTheStackFillsExactly(): iterable
    {
        foreach (ConstraintSweep::casesWhereEveryFloorFits() as $name => [$constraints, $extent]) {
            $growing = 0;
            $lastGrowingHasCeiling = false;

            foreach ($constraints as $constraint) {
                if (!self::growsInAStack($constraint)) {
                    continue;
                }
                ++$growing;
                $lastGrowingHasCeiling = $constraint->max !== PHP_INT_MAX;
            }

            if (!$lastGrowingHasCeiling && $growing > 0 && $extent - ConstraintSweep::floorsOf($constraints) >= $growing) {
                yield $name => [$constraints, $extent];
            }
        }
    }

    private static function growsInAStack(Constraint $constraint): bool
    {
        return $constraint->min !== $constraint->max
            && !($constraint->min > 0 && $constraint->flex === 0);
    }

    /**
     * @param list<Constraint> $constraints
     *
     * @return list<int>
     */
    private function heightsOf(array $constraints, int $extent): array
    {
        return VerticalStack::layoutUsing(new KeyedCache(), $this->widgetsFor($constraints), 40, $extent)->heights;
    }

    /**
     * @param list<Constraint> $constraints
     *
     * @return list<Widget>
     */
    private function widgetsFor(array $constraints): array
    {
        return array_map(static fn (Constraint $constraint): Widget => new ConstrainedWidget($constraint), $constraints);
    }
}
