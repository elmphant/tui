<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Rendering\WrapCache;
use Elmphant\Tui\Tests\Support\PerformanceBudget;
use Elmphant\Tui\Widget\FilterableTable;
use Elmphant\Tui\Widget\Table\StaticTable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FilterableTablePerfTest extends TestCase
{
    use PerformanceBudget;

    /**
     * The bar costs a little on top of the table it wraps. Measured against the
     * table itself rather than a stopwatch, so a loaded machine slows both.
     */
    private const MAX_COST_OF_THE_BAR = 1.6;

    #[Test]
    public function askingHowWideTheTableIsDoesNotMeasureEveryCellASecondTime(): void
    {
        self::skipUnderXdebug();

        $context = new RenderContext(Theme::dark());
        $area = new Rect(0, 0, 80, 12);
        $rows = self::rows(8_000);

        $table = null;
        $plain = self::medianMicrosecondsAfterSetup(
            static function () use (&$table, $rows): void {
                WrapCache::clear();
                $table = StaticTable::new(['Name', 'Method', 'Path'], $rows);
            },
            static function () use (&$table, $area, $context): void {
                $table?->render(new Frame(80, 12), $area, $context);
            },
            10,
        );

        $filterable = null;
        $wrapped = self::medianMicrosecondsAfterSetup(
            static function () use (&$filterable, $rows): void {
                WrapCache::clear();
                $filterable = FilterableTable::new(['Name', 'Method', 'Path'], $rows);
            },
            static function () use (&$filterable, $area, $context): void {
                $filterable?->render(new Frame(80, 12), $area, $context);
            },
            10,
        );

        $ratio = $wrapped / $plain;

        self::assertLessThan(
            self::MAX_COST_OF_THE_BAR,
            $ratio,
            sprintf(
                'The bar cost %.1fx the table it wraps (%.0fµs vs %.0fµs). Its own '
                . 'question about the width must not measure every cell again.',
                $ratio,
                $wrapped,
                $plain,
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
            $rows[] = ['row-' . $index, 'GET', '/path/to/thing/' . $index];
        }

        return $rows;
    }
}
