<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Widget\Choice\CheckboxGroup;
use Elmphant\Tui\Widget\Choice\CheckboxRow;
use Elmphant\Tui\Widget\Choice\RadioRow;
use Elmphant\Tui\Widget\Choice\Select;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Interactive;
use Elmphant\Tui\Widget\Listing\ListView;
use Elmphant\Tui\Widget\Listing\SimpleListItem;
use Elmphant\Tui\Widget\Table\Column;
use Elmphant\Tui\Widget\Table\Table;
use Elmphant\Tui\Widget\Tree\SimpleTreeNode;
use Elmphant\Tui\Widget\Tree\TreeView;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every container hands a key to one child, so a widget that is handed one is
 * the one the keyboard is on. Asking again is how it disagrees with the router.
 */
final class AWidgetActsOnWhatItIsHandedTest extends TestCase
{
    #[Test]
    #[DataProvider('widgetsAndAKeyTheyAnswer')]
    public function anUnflaggedWidgetAnswersTheKeyItIsHanded(Interactive $widget, KeyMsg $key): void
    {
        $answered = $widget->update($key, new Rect(0, 0, 30, 8))->model;

        self::assertNotSame($widget, $answered);
    }

    #[Test]
    public function anUnflaggedCheckboxGroupMovesItsCursorOnTheKeyItIsHanded(): void
    {
        $group = CheckboxGroup::new(['one', 'two']);

        $moved = $group->update(new KeyMsg(Key::Down), new Rect(0, 0, 30, 4))->model;

        self::assertNotSame($group, $moved);
    }

    #[Test]
    public function anUnflaggedCheckboxRowMovesItsCursorOnTheKeyItIsHanded(): void
    {
        $row = CheckboxRow::new(['one', 'two']);

        $moved = $row->update(new KeyMsg(Key::Right), new Rect(0, 0, 30, 2))->model;

        self::assertNotSame($row, $moved);
    }

    #[Test]
    public function anUnflaggedRadioRowMovesItsCursorOnTheKeyItIsHanded(): void
    {
        $row = RadioRow::new(['one', 'two']);

        $moved = $row->update(new KeyMsg(Key::Right), new Rect(0, 0, 30, 2))->model;

        self::assertNotSame($row, $moved);
    }

    #[Test]
    public function anUnflaggedTreeMovesItsCursorOnTheKeyItIsHanded(): void
    {
        $tree = TreeView::new([
            new SimpleTreeNode('a', 'a', 'a'),
            new SimpleTreeNode('b', 'b', 'b'),
        ]);

        $moved = $tree->update(new KeyMsg(Key::Down), new Rect(0, 0, 30, 8))->model;

        self::assertNotSame($tree, $moved);
    }

    #[Test]
    public function anUnflaggedTableMovesItsCursorOnTheKeyItIsHanded(): void
    {
        $table = Table::fromCells([Column::new('A', Constraint::exact(8))], [['one'], ['two']]);

        $moved = $table->update(new KeyMsg(Key::Down), new Rect(0, 0, 30, 8))->model;

        self::assertInstanceOf(Table::class, $moved);
        self::assertSame(1, $moved->cursor());
    }

    /**
     * @return iterable<string, array{Interactive, KeyMsg}>
     */
    public static function widgetsAndAKeyTheyAnswer(): iterable
    {
        yield 'TextInput' => [TextInput::new(), new KeyMsg('x')];
        yield 'Select' => [Select::new(['one', 'two']), new KeyMsg(Key::Down)];
        yield 'ListView' => [
            ListView::new([new SimpleListItem('one'), new SimpleListItem('two')]),
            new KeyMsg(Key::Down),
        ];
    }
}
