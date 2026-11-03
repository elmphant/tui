<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Rendering\Color;
use Elmphant\Tui\Rendering\Span;
use Elmphant\Tui\Rendering\Style;
use Elmphant\Tui\Widget\Table\Row;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RowTest extends TestCase
{
    #[Test]
    public function theCellsAndSelectabilityCanBeReplacedIndependently(): void
    {
        $row = Row::new('Alice', 'Active');

        $recelled = $row->withCells(['Bob']);
        self::assertSame(['Bob'], $recelled->cells);
        self::assertTrue($recelled->selectable, 'Replacing cells leaves selectability alone');

        $locked = $row->withSelectable(false);
        self::assertFalse($locked->selectable);
        self::assertCount(2, $locked->cells, 'And the reverse holds');

        self::assertNotSame($row, $recelled);
        self::assertSame($row, $row->withSelectable(true), 'An unchanged value returns the same row');
    }

    #[Test]
    public function aRowAnswersTheTextOfEachCellWhetherPlainOrStyled(): void
    {
        $row = Row::new('Alice', Span::styled('Active', new Style(foreground: Color::ansi(2))));

        self::assertSame(['Alice', 'Active'], $row->texts());
    }

    #[Test]
    public function namedCellsAreKeptInTheOrderGiven(): void
    {
        self::assertSame(['1', 'Alice'], Row::new(id: '1', name: 'Alice')->cells);
    }

    #[Test]
    public function newCreatesSelectableRow(): void
    {
        $row = Row::new('Alice', 'Active');
        self::assertCount(2, $row->cells);
        self::assertTrue($row->selectable);
        self::assertNull($row->style);
    }

    #[Test]
    public function headerCreatesNonSelectableRow(): void
    {
        $row = Row::header('Section Title');
        self::assertCount(1, $row->cells);
        self::assertFalse($row->selectable);
    }

    #[Test]
    public function rowAcceptsSpanCells(): void
    {
        $style = new Style(foreground: Color::ansi(1));
        $row = Row::new(
            Span::styled('red', $style),
            'plain',
        );
        self::assertCount(2, $row->cells);
        self::assertInstanceOf(Span::class, $row->cells[0]);
        self::assertSame('plain', $row->cells[1]);
    }

    #[Test]
    public function rowAcceptsStyleOverride(): void
    {
        $style = new Style(foreground: Color::ansi(2));
        $row = Row::new('cell')->withStyle($style);
        self::assertSame($style, $row->style);
    }
}
