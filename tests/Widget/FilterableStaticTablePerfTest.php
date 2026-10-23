<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Search\Matcher;
use Elmphant\Tui\Search\SubstringMatcher;
use Elmphant\Tui\Tests\Support\PerformanceBudget;
use Elmphant\Tui\Text\WrapCache;
use Elmphant\Tui\Widget\Filter\FilterableStaticTable;
use Elmphant\Tui\Widget\Table\StaticTable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FilterableStaticTablePerfTest extends TestCase
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
                $filterable = FilterableStaticTable::new(['Name', 'Method', 'Path'], $rows);
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

    #[Test]
    public function aTableRebuiltEveryFrameAsksItsMatcherOncePerQuery(): void
    {
        TallyingMatcher::$runs = 0;
        $context = RenderContext::unfocused(Theme::dark());
        $area = new Rect(0, 0, 80, 12);
        $rows = self::rows(50);

        foreach (range(1, 3) as $frame) {
            $table = FilterableStaticTable::new(['Name', 'Method', 'Path'], $rows)
                ->withMatcher(new TallyingMatcher())
                ->withQuery('row-1');
            $table->measureHeight(80);
            $table->render(new Frame(80, 12), $area, $context);
        }

        self::assertSame(1, TallyingMatcher::$runs, 'the same rows and query were matched again');
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

/**
 * The tally is static: a count on the instance would change what the matcher serializes to, and so its cache key.
 */
final class TallyingMatcher implements Matcher
{
    public static int $runs = 0;

    public function matching(string $query, array $haystack): array
    {
        ++self::$runs;

        return (new SubstringMatcher())->matching($query, $haystack);
    }
}
