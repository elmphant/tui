<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Widget\Table;
use Elmphant\Tui\Widget\Table\Column;
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
}
