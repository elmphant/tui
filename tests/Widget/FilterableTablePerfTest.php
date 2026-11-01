<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Search\SubstringMatcher;
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

    #[Test]
    public function aKeystrokeCostsAboutWhatMatchingAndTheNewRowsCost(): void
    {
        self::skipUnderXdebug();

        $columns = [Column::new('Name', Constraint::exact(14)), Column::new('Description', Constraint::flex())];
        $rows = [];
        $texts = [];
        for ($index = 0; $index < 50_000; ++$index) {
            $rows[] = Row::new("name {$index}", "description of row {$index}");
            $texts[] = "name {$index} description of row {$index}";
        }
        $filterable = FilterableTable::new($columns, $rows);
        $table = Table::new($columns, $rows);
        $matcher = new SubstringMatcher();
        $kept = array_map(static fn (int $index): Row => $rows[$index], $matcher->matching('9', $texts));

        $keystroke = self::medianMicroseconds(static fn (): FilterableTable => $filterable->withQuery('9'), 7);
        $unavoidable = self::medianMicroseconds(static fn (): Table => $table->withRows($kept), 7)
            + self::medianMicroseconds(static fn (): array => $matcher->matching('9', $texts), 7);

        self::assertLessThan(1.5, $keystroke / $unavoidable, sprintf('%.0fµs against %.0fµs', $keystroke, $unavoidable));
    }
}
