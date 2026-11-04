<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\Chosen;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Testing\UserInput;
use Elmphant\Tui\Widget\Choice\Radio;
use Elmphant\Tui\Widget\Choice\Select;
use Elmphant\Tui\Widget\Filter\FilterableList;
use Elmphant\Tui\Widget\Filter\FilterableTable;
use Elmphant\Tui\Widget\Listing\ListView;
use Elmphant\Tui\Widget\Popup\Dropdown;
use Elmphant\Tui\Widget\Table\Column;
use Elmphant\Tui\Widget\Table\Row;
use Elmphant\Tui\Widget\Table\Table;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ChosenTest extends TestCase
{
    #[Test]
    public function enterOnAListHandsOnTheItemUnderTheCursor(): void
    {
        $list = ListView::fromStrings(['one', 'two'])->withCursor(1);
        $enter = new KeyMsg(Key::Enter);

        self::assertEquals(new Chosen($list->items()[1], $enter), $list->outcomeOf($enter, self::area()));
    }

    #[Test]
    public function aClickOnAListItemHandsOnThatItem(): void
    {
        $list = ListView::fromStrings(['one', 'two']);
        $click = UserInput::press(1, 1);

        self::assertEquals(new Chosen($list->items()[1], $click), $list->outcomeOf($click, self::area()));
    }

    #[Test]
    public function aClickBelowTheLastListItemChoosesNothing(): void
    {
        self::assertNull(ListView::fromStrings(['one', 'two'])->outcomeOf(UserInput::press(1, 5), self::area()));
    }

    #[Test]
    public function enterOnATableHandsOnTheRowUnderTheCursor(): void
    {
        $table = Table::fromCells([Column::new('Name', Constraint::flex())], [['alpha'], ['beta']])->withCursor(1);
        $enter = new KeyMsg(Key::Enter);

        $outcome = $table->outcomeOf($enter, self::area());

        self::assertInstanceOf(Chosen::class, $outcome);
        self::assertInstanceOf(Row::class, $outcome->choice);
        self::assertSame(['beta'], $outcome->choice->texts());
        self::assertSame($enter, $outcome->trigger);
    }

    #[Test]
    public function aClickOnATableRowHandsOnThatRow(): void
    {
        $click = UserInput::press(1, 2);

        $outcome = Table::fromCells([Column::new('Name', Constraint::flex())], [['alpha'], ['beta']])->outcomeOf($click, self::area());

        self::assertInstanceOf(Chosen::class, $outcome);
        self::assertInstanceOf(Row::class, $outcome->choice);
        self::assertSame(['beta'], $outcome->choice->texts());
        self::assertSame($click, $outcome->trigger);
    }

    #[Test]
    public function aClickOnATableHeaderChoosesNothing(): void
    {
        self::assertNull(
            Table::fromCells([Column::new('Name', Constraint::flex())], [['alpha']])->outcomeOf(UserInput::press(1, 0), self::area()),
        );
    }

    #[Test]
    public function enterOnAFilterableListHandsOnTheItemUnderTheCursor(): void
    {
        $items = ListView::fromStrings(['one', 'two'])->items();
        $enter = new KeyMsg(Key::Enter);

        self::assertEquals(new Chosen($items[1], $enter), FilterableList::new($items)->withCursorSourceIndex(1)->outcomeOf($enter, self::area()));
    }

    #[Test]
    public function enterOnAFilterableTableHandsOnTheRowUnderTheCursor(): void
    {
        $enter = new KeyMsg(Key::Enter);

        $outcome = FilterableTable::new([Column::new('Name', Constraint::flex())], [Row::new('alpha'), Row::new('beta')])->outcomeOf($enter, self::area());

        self::assertInstanceOf(Chosen::class, $outcome);
        self::assertInstanceOf(Row::class, $outcome->choice);
        self::assertSame(['alpha'], $outcome->choice->texts());
    }

    #[Test]
    public function enterOnASelectHandsOnTheOptionUnderTheCursor(): void
    {
        $enter = new KeyMsg(Key::Enter);

        self::assertEquals(new Chosen('yaml', $enter), Select::new(['json', 'yaml'])->withCursor(1)->outcomeOf($enter, self::area()));
    }

    #[Test]
    public function aClickOnASelectOptionHandsOnThatOption(): void
    {
        $click = UserInput::press(2, 1);

        self::assertEquals(new Chosen('yaml', $click), Select::new(['json', 'yaml'])->outcomeOf($click, self::area()));
    }

    #[Test]
    public function aClickOnARadioOptionHandsOnThatOption(): void
    {
        $click = UserInput::press(2, 1);

        self::assertEquals(new Chosen('high', $click), Radio::new(['low', 'high'])->outcomeOf($click, self::area()));
    }

    #[Test]
    public function enterInAnOpenDropdownHandsOnWhatItsContentChose(): void
    {
        $enter = new KeyMsg(Key::Enter);

        self::assertEquals(
            new Chosen('yaml', $enter),
            Dropdown::new(Select::new(['json', 'yaml'])->withCursor(1))->withOpen()->outcomeOf($enter, self::area()),
        );
    }

    #[Test]
    public function enterOnAClosedDropdownOpensItAndChoosesNothing(): void
    {
        self::assertNull(Dropdown::new(Select::new(['json', 'yaml']))->outcomeOf(new KeyMsg(Key::Enter), self::area()));
    }

    #[Test]
    public function enterOnARadioHandsOnTheOptionUnderTheCursor(): void
    {
        $enter = new KeyMsg(Key::Enter);

        self::assertEquals(new Chosen('high', $enter), Radio::new(['low', 'high'])->withCursor(1)->outcomeOf($enter, self::area()));
    }

    private static function area(): Rect
    {
        return new Rect(0, 0, 40, 10);
    }
}
