<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Tests\Support\PerformanceBudget;
use Elmphant\Tui\Text\WrapCache;
use Elmphant\Tui\Widget\Table\StaticTable;
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

    #[Test]
    public function aRebuiltTableDrawsAtANewWidthWithoutMeasuringItsCellsAgain(): void
    {
        self::skipUnderXdebug();

        $context = new RenderContext(Theme::dark());
        $rows = self::rows(16_000);
        $headers = ['A', 'B', 'C'];

        $cold = self::medianMicrosecondsAfterSetup(
            static function (): void {
                WrapCache::clear();
            },
            static function () use ($headers, $rows, $context): void {
                StaticTable::new($headers, $rows)->render(new Frame(60, 10), new Rect(0, 0, 60, 10), $context);
            },
            10,
        );

        // A new width, so the drawing is genuinely new work and only the
        // measuring can come from the cache.
        StaticTable::new($headers, $rows)->render(new Frame(60, 10), new Rect(0, 0, 60, 10), $context);
        $width = 61;
        $warm = self::medianMicroseconds(
            static function () use ($headers, $rows, $context, &$width): void {
                ++$width;
                StaticTable::new($headers, $rows)
                    ->render(new Frame($width, 10), new Rect(0, 0, $width, 10), $context);
            },
            10,
        );

        $ratio = $warm / $cold;

        self::assertLessThan(
            0.6,
            $ratio,
            sprintf(
                'Drawing the same rows at a new width cost %.2f of a cold draw (%.0fµs vs '
                . '%.0fµs). The column widths do not change with the area, so measuring '
                . 'them must not run again for every frame.',
                $ratio,
                $warm,
                $cold,
            ),
        );
    }

    #[Test]
    public function scrollingATableRebuiltEveryFrameDoesNotDrawItAgainForEveryLine(): void
    {
        $context = RenderContext::unfocused(Theme::dark());
        $rows = self::rows(300);

        $drawAt = static function (int $scroll) use ($rows, $context): void {
            StaticTable::new(['A', 'B', 'C'], $rows)
                ->render(new Frame(60, 10), new Rect(0, -$scroll, 60, 310), $context);
        };

        $drawAt(0);
        $firstFrame = WrapCache::wraps();
        foreach (range(1, 20) as $scroll) {
            $drawAt($scroll);
        }

        self::assertSame($firstFrame, WrapCache::wraps(), 'a line scrolled drew the table again');
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
