<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Widget\Table;
use Elmphant\Tui\Widget\Table\Column;
use Elmphant\Tui\Widget\Table\Row;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TableRowReloadTest extends TestCase
{
    #[Test]
    public function reloadingRowsKeepsTheReaderOnTheRowTheCursorWasOn(): void
    {
        $columns = [Column::new('A', Constraint::flex())];
        $table = Table\Table::fromCells($columns, [['alpha'], ['beta']])->withCursor(1);

        $reordered = $table->withCells([['beta'], ['alpha']]);

        self::assertSame(0, $reordered->cursor(), 'beta moved to the top, and the reader with it');
    }

    #[Test]
    public function aKeyedRowKeepsTheCursorAndTheSelectionWhileItsCellsChange(): void
    {
        $table = Table\Table::new($this->columns(), [
            Row::new('1', '0.0')->withKey('1'),
            Row::new('42', '1.0')->withKey('42'),
        ])->withCursor(1)->withSelectedIndex(1);

        $sampled = $table->withRows([
            Row::new('42', '7.5')->withKey('42'),
            Row::new('1', '0.0')->withKey('1'),
        ]);

        self::assertSame([0, 0], [$sampled->cursor(), $sampled->selectedIndex()]);
    }

    #[Test]
    public function aCursorWhosePlaceIsNowAHeadingMovesUpToTheRowAboveIt(): void
    {
        $table = Table\Table::fromCells($this->columns(), [['a'], ['b'], ['c']])->withCursor(2);

        $reloaded = $table->withRows([Row::new('a'), Row::new('b'), Row::header('later')]);

        self::assertSame(1, $reloaded->cursor(), 'nothing below can be chosen, so the nearest row above is');
    }

    #[Test]
    public function aCursorOverRowsThatCannotBeChosenRestsOnTheFirst(): void
    {
        $table = Table\Table::fromCells($this->columns(), [['a'], ['b'], ['c']])->withCursor(2);

        self::assertSame(0, $table->withRows([Row::header('one'), Row::header('two'), Row::header('three')])->cursor());
    }

    #[Test]
    public function theCursorLandsOnTheNeighbourThatTookTheRowsPlaceWhenItsRowIsGone(): void
    {
        $table = Table\Table::fromCells($this->columns(), [['1'], ['42'], ['77'], ['90']])->withCursor(2);

        $sampled = $table->withCells([['1'], ['42'], ['90']]);

        self::assertSame(2, $sampled->cursor());
    }

    #[Test]
    public function theCursorStepsBackToTheLastRowWhenTheLastRowIsGone(): void
    {
        $table = Table\Table::fromCells($this->columns(), [['1'], ['42'], ['77']])->withCursor(2);

        self::assertSame(1, $table->withCells([['1'], ['42']])->cursor());
    }

    #[Test]
    public function rowsReadingAlikeKeepTheCursorOnTheOneNearestWhereItWas(): void
    {
        $table = Table\Table::fromCells($this->columns(), [['same'], ['other'], ['same']])->withCursor(2);

        self::assertSame(2, $table->withCells([['same'], ['other'], ['same']])->cursor());
    }

    /**
     * @return list<Column>
     */
    private function columns(): array
    {
        return [Column::new('PID', Constraint::flex()), Column::new('CPU', Constraint::flex())];
    }
}
