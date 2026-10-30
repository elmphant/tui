<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Widget\Table\Row;
use Elmphant\Tui\Widget\Table\TableLayout;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Row starts count wrapped lines so the scrollbar tracks actual content height.
 */
final class TableLayoutLinesTest extends TestCase
{
    #[Test]
    #[DataProvider('rows')]
    public function aRowStartsAfterEveryLineTheRowsAboveItTakeUp(int $row, int $expected): void
    {
        // 'one two three' wraps to three lines at width 5, 'flat' takes one.
        $layout = TableLayout::forRows([5], [
            Row::new('one two three'),
            Row::new('flat'),
            Row::new('one two three'),
        ]);

        self::assertSame($expected, $layout->lineOf($row));
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function rows(): iterable
    {
        yield 'the first row starts at the top' => [0, 0];
        yield 'after a row that wrapped' => [1, 3];
        yield 'after a wrapped row and a flat one' => [2, 4];
        yield 'past the last row is every line there is' => [3, 7];
        yield 'beyond the rows it holds it stops counting' => [9, 7];
    }
}
