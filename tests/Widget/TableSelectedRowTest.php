<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Rendering\Span;
use Elmphant\Tui\Rendering\Style;
use Elmphant\Tui\Widget\Table;
use Elmphant\Tui\Widget\Table\Column;
use Elmphant\Tui\Widget\Table\Row;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TableSelectedRowTest extends TestCase
{
    #[Test]
    public function aTypedTableReportsTheSelectedRowsText(): void
    {
        $table = Table\Table::new(self::columns(), [
            Row::new('alpha', 'eu-1a'),
            Row::new('beta', 'eu-1b'),
        ])->withSelected(1);

        self::assertSame(['beta', 'eu-1b'], $table->selectedRow());
    }

    #[Test]
    public function aTypedTableReportsTheSelectedRowItself(): void
    {
        $second = Row::new('beta', 'eu-1b');
        $table = Table\Table::new(self::columns(), [Row::new('alpha', 'eu-1a'), $second])->withSelected(1);

        self::assertSame($second, $table->selectedTypedRow());
    }

    #[Test]
    public function spanCellsAreReportedAsTheirText(): void
    {
        $table = Table\Table::new(self::columns(), [
            Row::new(Span::styled('gamma', new Style()), 'eu-1c'),
        ])->withSelected(0);

        self::assertSame(['gamma', 'eu-1c'], $table->selectedRow());
    }

    #[Test]
    public function aPlainTableStillReportsItsSelectedRow(): void
    {
        $table = Table\Table::fromCells(self::columns(), [['alpha', 'eu-1a'], ['beta', 'eu-1b']])->withSelected(1);

        self::assertSame(['beta', 'eu-1b'], $table->selectedRow());
    }

    #[Test]
    public function nothingSelectedReportsNothing(): void
    {
        $typed = Table\Table::new(self::columns(), [Row::new('alpha', 'eu-1a')]);

        self::assertNull($typed->selectedRow());
        self::assertNull($typed->selectedTypedRow());
    }

    #[Test]
    public function aSelectionPastTheLastRowReportsNothing(): void
    {
        $typed = Table\Table::new(self::columns(), [Row::new('alpha', 'eu-1a')])->withSelected(9);

        self::assertNull($typed->selectedRow());
        self::assertNull($typed->selectedTypedRow());
    }

    #[Test]
    public function aPlainTableReportsTheRowItBuiltFromTheCells(): void
    {
        $plain = Table\Table::fromCells(self::columns(), [['alpha', 'eu-1a']])->withSelected(0);

        self::assertSame(['alpha', 'eu-1a'], $plain->selectedTypedRow()?->cells);
    }

    /**
     * @return list<Column>
     */
    /**
     * Preserve selection by record key after refresh; clear it when the record disappears.
     */
    #[Test]
    public function theSelectionStaysOnTheRecordItWasMadeOn(): void
    {
        $columns = [Column::new('Name', Constraint::flex()), Column::new('State', Constraint::flex())];
        $chosen = Table\Table::fromCells($columns, [['alpha', 'on'], ['beta', 'off'], ['gamma', 'on']])->withSelected(2);
        self::assertSame(['gamma', 'on'], $chosen->selectedRow(), 'precondition: gamma was chosen');

        $refreshed = $chosen->withCells([['zulu', 'off'], ['alpha', 'on'], ['beta', 'off'], ['gamma', 'on']]);
        self::assertSame(['gamma', 'on'], $refreshed->selectedRow(), 'a row added above moves nothing');

        $withoutGamma = $chosen->withCells([['alpha', 'on'], ['beta', 'off']]);
        self::assertNull($withoutGamma->selectedRow(), 'the record went, so the selection went with it');
    }

    private static function columns(): array
    {
        return [
            Column::new('NAME', Constraint::flex()),
            Column::new('ZONE', Constraint::exact(6)),
        ];
    }
}
