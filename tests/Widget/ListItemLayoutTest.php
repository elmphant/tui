<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Widget\Listing\ListItemLayout;
use Elmphant\Tui\Widget\Listing\SimpleListItem;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ListItemLayoutTest extends TestCase
{
    #[Test]
    public function aTitleShortEnoughOccupiesOneRow(): void
    {
        $rows = ListItemLayout::rows(new SimpleListItem('alpha'), 10);

        self::assertSame([['text' => 'alpha', 'indent' => 0, 'isDescription' => false]], $rows);
        self::assertSame(1, ListItemLayout::height(new SimpleListItem('alpha'), 10));
    }

    #[Test]
    public function aTitleKeepsItsIndentOnEveryWrappedRow(): void
    {
        $rows = ListItemLayout::rows(new SimpleListItem('    notes about the release'), 16);

        self::assertSame([
            ['text' => 'notes about', 'indent' => 4, 'isDescription' => false],
            ['text' => 'the release', 'indent' => 4, 'isDescription' => false],
        ], $rows);
    }

    #[Test]
    public function theHeightIsAlwaysTheNumberOfRowsDrawn(): void
    {
        $items = [
            new SimpleListItem(''),
            new SimpleListItem('exactly10!'),
            new SimpleListItem('exactly 11!'),
            new SimpleListItem('中文中文中'),
            new SimpleListItem('中文中文中文'),
            new SimpleListItem('fits', 'with a description'),
            new SimpleListItem('one two three four five six seven eight nine ten eleven'),
        ];

        foreach ($items as $item) {
            foreach ([0, 1, 10] as $width) {
                self::assertSame(
                    count(ListItemLayout::rows($item, $width)),
                    ListItemLayout::height($item, $width),
                    "'{$item->title()}' at width {$width}",
                );
            }
        }
    }

    #[Test]
    public function oneColumnStillWrapsRatherThanGivingUp(): void
    {
        $rows = ListItemLayout::rows(new SimpleListItem('ab'), 1);

        self::assertSame(['a', 'b'], array_column($rows, 'text'), 'A single column is a usable width');
    }

    #[Test]
    public function noWidthAtAllLeavesOneEmptyRow(): void
    {
        self::assertSame(
            [['text' => '', 'indent' => 0, 'isDescription' => false]],
            ListItemLayout::rows(new SimpleListItem('alpha'), 0),
        );
    }

    #[Test]
    public function aDescriptionIsIndentedUnderItsTitleAndMarkedAsOne(): void
    {
        $rows = ListItemLayout::rows(new SimpleListItem('title', 'detail'), 10);

        self::assertSame(
            [
                ['text' => 'title', 'indent' => 0, 'isDescription' => false],
                ['text' => 'detail', 'indent' => 2, 'isDescription' => true],
            ],
            $rows,
        );
    }

    #[Test]
    public function theDescriptionWrapsInsideItsIndent(): void
    {
        // Eight columns less the two-column indent leaves six for the description,
        // so it breaks where the title would not have.
        $rows = ListItemLayout::rows(new SimpleListItem('abcdefgh', 'wwwwww xx'), 8);

        self::assertSame(['abcdefgh', 'wwwwww', 'xx'], array_column($rows, 'text'));
        self::assertSame([0, 2, 2], array_column($rows, 'indent'));
    }

    #[Test]
    public function anItemNeverGrowsPastFourRows(): void
    {
        $item = new SimpleListItem('one two', 'three four five six seven eight nine ten');

        $rows = ListItemLayout::rows($item, 6);

        self::assertCount(4, $rows);
        self::assertSame(4, ListItemLayout::height($item, 6));
    }

    #[Test]
    public function theCappedRowSaysTheTextCarriesOn(): void
    {
        $item = new SimpleListItem('one two', 'three four five six seven eight nine ten');

        $rows = ListItemLayout::rows($item, 6);

        // The fourth row keeps its own text and picks up the row that follows it,
        // ellipsized to the four columns left inside the description indent.
        self::assertSame('e f…', $rows[3]['text']);
    }
}
