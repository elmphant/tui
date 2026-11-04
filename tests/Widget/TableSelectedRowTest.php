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
    public function aTableReportsTheSelectedRowItself(): void
    {
        $second = Row::new('beta', 'eu-1b');
        $table = Table\Table::new(self::columns(), [Row::new('alpha', 'eu-1a'), $second])->withSelectedIndex(1);

        self::assertSame($second, $table->selectedRow());
    }

    #[Test]
    public function theSelectedRowGivesItsSpanCellsAsText(): void
    {
        $table = Table\Table::new(self::columns(), [
            Row::new(Span::styled('gamma', new Style()), 'eu-1c'),
        ])->withSelectedIndex(0);

        self::assertSame(['gamma', 'eu-1c'], $table->selectedRow()?->texts());
    }

    #[Test]
    public function aPlainTableReportsTheRowItBuiltFromTheCells(): void
    {
        $plain = Table\Table::fromCells(self::columns(), [['alpha', 'eu-1a'], ['beta', 'eu-1b']])->withSelectedIndex(1);

        self::assertSame(['beta', 'eu-1b'], $plain->selectedRow()?->cells);
    }

    #[Test]
    public function nothingSelectedReportsNothing(): void
    {
        self::assertNull(Table\Table::new(self::columns(), [Row::new('alpha', 'eu-1a')])->selectedRow());
    }

    #[Test]
    public function aSelectionPastTheLastRowReportsNothing(): void
    {
        self::assertNull(Table\Table::new(self::columns(), [Row::new('alpha', 'eu-1a')])->withSelectedIndex(9)->selectedRow());
    }

    #[Test]
    public function theSelectionStaysOnTheRecordItWasMadeOn(): void
    {
        $columns = [Column::new('Name', Constraint::flex()), Column::new('State', Constraint::flex())];
        $chosen = Table\Table::fromCells($columns, [['alpha', 'on'], ['beta', 'off'], ['gamma', 'on']])->withSelectedIndex(2);
        self::assertSame(['gamma', 'on'], $chosen->selectedRow()?->cells, 'precondition: gamma was chosen');

        $refreshed = $chosen->withCells([['zulu', 'off'], ['alpha', 'on'], ['beta', 'off'], ['gamma', 'on']]);
        self::assertSame(['gamma', 'on'], $refreshed->selectedRow()?->cells, 'a row added above moves nothing');

        $withoutGamma = $chosen->withCells([['alpha', 'on'], ['beta', 'off']]);
        self::assertNull($withoutGamma->selectedRow(), 'the record went, so the selection went with it');
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
