<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Rendering\WrapCache;
use Elmphant\Tui\Tests\Support\PerformanceBudget;
use Elmphant\Tui\Widget\StaticTable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StaticTablePerfTest extends TestCase
{
    use PerformanceBudget;

    /**
     * Drawing is linear in the rows, so twice the rows costs about twice as
     * much. The allowance absorbs the container's spread without admitting a
     * growth class above linear.
     */
    private const MAX_COST_OF_DOUBLING = 3.0;

    #[Test]
    public function twiceTheRowsCostAboutTwiceAsMuchToDraw(): void
    {
        self::skipUnderXdebug();

        $context = new RenderContext(Theme::dark());
        $frame = new Frame(60, 10);
        $area = new Rect(0, 0, 60, 10);

        $smallRows = self::rows(2_000);
        $largeRows = self::rows(4_000);
        $table = null;

        // The drawn grid is cached by content, so only a cleared cache measures
        // the drawing itself rather than a lookup.
        $smallCost = self::medianMicrosecondsAfterSetup(
            static function () use (&$table, $smallRows): void {
                WrapCache::clear();
                $table = StaticTable::new(['A', 'B', 'C'], $smallRows);
            },
            static function () use (&$table, $frame, $area, $context): void {
                $table?->render($frame, $area, $context);
            },
            10,
        );
        $largeCost = self::medianMicrosecondsAfterSetup(
            static function () use (&$table, $largeRows): void {
                WrapCache::clear();
                $table = StaticTable::new(['A', 'B', 'C'], $largeRows);
            },
            static function () use (&$table, $frame, $area, $context): void {
                $table?->render($frame, $area, $context);
            },
            10,
        );
        $ratio = $largeCost / $smallCost;

        self::assertLessThan(
            self::MAX_COST_OF_DOUBLING,
            $ratio,
            sprintf(
                'Doubling the rows cost %.1fx (%.0fµs vs %.0fµs). Drawing must not '
                . 'rebuild the lines it has already drawn for every further row.',
                $ratio,
                $largeCost,
                $smallCost,
            ),
        );
    }

    /**
     * @return list<list<string>>
     */
    private static function rows(int $count): array
    {
        $rows = [];

        for ($index = 0; $index < $count; ++$index) {
            $rows[] = ["row-$index", "value $index", 'note'];
        }

        return $rows;
    }
}
