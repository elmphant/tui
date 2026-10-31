<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Tests\Support\PerformanceBudget;
use Elmphant\Tui\Widget\Filter\FilterableTable;
use Elmphant\Tui\Widget\Table\Column;
use Elmphant\Tui\Widget\Table\Row;
use Elmphant\Tui\Widget\Table\Table;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FilterableTablePerfTest extends TestCase
{
    use PerformanceBudget;

    private const MAX_COST_OF_THE_FILTER = 3.0;

    #[Test]
    public function repaintingAnUnchangedFilteredTableCostsAboutWhatTheTableDoes(): void
    {
        self::skipUnderXdebug();

        $context = RenderContext::unfocused(Theme::dark());
        $area = new Rect(0, 0, 80, 24);
        $columns = [Column::new('Name', Constraint::flex()), Column::new('Path', Constraint::flex())];
        $rows = array_map(static fn (int $index): Row => Row::new('row-' . $index, '/path/to/thing/' . $index), range(0, 49_999));
        $table = Table::new($columns, $rows);
        $filterable = FilterableTable::new($columns, $rows)->withQuery('row-4');

        self::assertRatioBelow(self::MAX_COST_OF_THE_FILTER, static function () use ($table, $filterable, $area, $context): array {
            $plain = self::medianMicroseconds(static fn () => $table->render(new Frame(80, 24), $area, $context), 20);
            $filtered = self::medianMicroseconds(static fn () => $filterable->render(new Frame(80, 24), $area, $context), 20);
            $ratio = $filtered / $plain;

            return [$ratio, sprintf('A repaint of the filtered table cost %.1fx the plain one (%.0fµs vs %.0fµs)', $ratio, $filtered, $plain)];
        });
    }
}
