<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Widget\Column;
use Elmphant\Tui\Widget\Table;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A reader selects a record, not a row number. A table refreshed with a row added
 * above the one they chose would answer with whatever slid into that place — and a
 * host acting on `selectedRow()` would act on the wrong record with nothing on the
 * screen to say so.
 */
final class ATableKeepsItsSelectionOnTheRowItWasMadeOnTest extends TestCase
{
    #[Test]
    public function aRowAddedAboveDoesNotMoveTheSelectionOntoAnother(): void
    {
        $columns = [Column::new('Name', Constraint::flex()), Column::new('State', Constraint::flex())];
        $chosen = Table::new($columns, [['alpha', 'on'], ['beta', 'off'], ['gamma', 'on']])
            ->withSelected(2);
        self::assertSame(['gamma', 'on'], $chosen->selectedRow(), 'precondition: the reader chose gamma');

        $refreshed = $chosen->withRows([['zulu', 'off'], ['alpha', 'on'], ['beta', 'off'], ['gamma', 'on']]);

        self::assertSame(['gamma', 'on'], $refreshed->selectedRow(), 'a refresh answers with the record they chose');
    }

    #[Test]
    public function aRowThatIsGoneLeavesNothingSelected(): void
    {
        $columns = [Column::new('Name', Constraint::flex()), Column::new('State', Constraint::flex())];
        $chosen = Table::new($columns, [['alpha', 'on'], ['beta', 'off'], ['gamma', 'on']])
            ->withSelected(2);

        $refreshed = $chosen->withRows([['alpha', 'on'], ['beta', 'off']]);

        self::assertNull($refreshed->selectedRow(), 'the record went, so the selection went with it');
    }
}
