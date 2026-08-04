<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Rendering\Span;
use Elmphant\Tui\Rendering\Style;
use Elmphant\Tui\Widget\Column;
use Elmphant\Tui\Widget\Row;
use Elmphant\Tui\Widget\Table;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Row is the only way to style a row, so a table that colours anything must use
 * typed rows — and the selection accessors have to work for those too.
 */
final class TableSelectedRowTest extends TestCase
{
    #[Test]
    public function aTypedTableReportsTheSelectedRowsText(): void
    {
        $table = Table::fromRows(self::columns(), [
            Row::new('alpha', 'eu-1a'),
            Row::new('beta', 'eu-1b'),
        ])->withSelected(1);

        self::assertSame(['beta', 'eu-1b'], $table->selectedRow());
    }

    #[Test]
    public function aTypedTableReportsTheSelectedRowItself(): void
    {
        $second = Row::new('beta', 'eu-1b');
        $table = Table::fromRows(self::columns(), [Row::new('alpha', 'eu-1a'), $second])->withSelected(1);

        self::assertSame($second, $table->selectedTypedRow());
    }

    #[Test]
    public function spanCellsAreReportedAsTheirText(): void
    {
        $table = Table::fromRows(self::columns(), [
            Row::new(Span::styled('gamma', new Style()), 'eu-1c'),
        ])->withSelected(0);

        self::assertSame(['gamma', 'eu-1c'], $table->selectedRow());
    }

    #[Test]
    public function aPlainTableStillReportsItsSelectedRow(): void
    {
        $table = Table::new(self::columns(), [['alpha', 'eu-1a'], ['beta', 'eu-1b']])->withSelected(1);

        self::assertSame(['beta', 'eu-1b'], $table->selectedRow());
    }

    #[Test]
    public function nothingSelectedReportsNothing(): void
    {
        $typed = Table::fromRows(self::columns(), [Row::new('alpha', 'eu-1a')]);

        self::assertNull($typed->selectedRow());
        self::assertNull($typed->selectedTypedRow());
    }

    #[Test]
    public function aSelectionPastTheLastRowReportsNothing(): void
    {
        $typed = Table::fromRows(self::columns(), [Row::new('alpha', 'eu-1a')])->withSelected(9);

        self::assertNull($typed->selectedRow());
        self::assertNull($typed->selectedTypedRow());
    }

    #[Test]
    public function aPlainTableHasNoTypedRowToReport(): void
    {
        $plain = Table::new(self::columns(), [['alpha', 'eu-1a']])->withSelected(0);

        self::assertNull($plain->selectedTypedRow());
    }

    /**
     * @return list<Column>
     */
    private static function columns(): array
    {
        return [
            Column::new('NAME', Constraint::flex()),
            Column::new('ZONE', Constraint::exact(6)),
        ];
    }
}
