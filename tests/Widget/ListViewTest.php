<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Closure;
use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseButton;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Rendering\Attr;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Testing\ScreenBuffer;
use Elmphant\Tui\Widget\Bar\Scrollbar;
use Elmphant\Tui\Widget\Focusable;
use Elmphant\Tui\Widget\Listing\ListItem;
use Elmphant\Tui\Widget\Listing\ListItemLayout;
use Elmphant\Tui\Widget\Listing\ListView;
use Elmphant\Tui\Widget\Listing\SimpleListItem;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ListViewTest extends TestCase
{
    #[Test]
    public function aCursorPastTheLastItemRestsOnTheLast(): void
    {
        self::assertSame(1, ListView::fromStrings(['a', 'b'])->withCursor(9)->cursor());
    }

    #[Test]
    public function aSelectionPastTheLastItemSelectsNothing(): void
    {
        self::assertNull(ListView::fromStrings(['a', 'b'])->withSelected(9)->selectedItem());
        self::assertNull(ListView::fromStrings(['a', 'b'])->withSelected(9)->selectedIndex());
    }

    #[Test]
    public function measuresTheRowsItsItemsNeed(): void
    {
        $list = ListView::new([new SimpleListItem('a'), new SimpleListItem('b'), new SimpleListItem('c')]);

        self::assertSame(3, $list->measureHeight(20), 'Three one-row items need three rows, not a share of the pane');
    }

    #[Test]
    public function theItemsAndSelectionCanBeReplacedAfterConstruction(): void
    {
        $list = ListView::new([new SimpleListItem('first')]);

        self::assertSame(1, $list->itemCount());

        $replaced = $list->withItems([new SimpleListItem('a'), new SimpleListItem('b'), new SimpleListItem('c')]);

        self::assertSame(3, $replaced->itemCount());
        self::assertNull($replaced->selectedIndex(), 'Nothing is selected to begin with');
        self::assertSame(1, $replaced->withSelected(1)->selectedIndex());
    }

    #[Test]
    public function aClickReportsTheItemUnderItAndNothingOutsideTheArea(): void
    {
        $area = new Rect(0, 0, 20, 4);
        $list = ListView::new([
            new SimpleListItem('a'), new SimpleListItem('b'),
            new SimpleListItem('c'), new SimpleListItem('d'),
        ]);

        self::assertSame(0, $list->indexAt(new MouseMsg(0, 0, MouseAction::Press, MouseButton::Left), $area));
        self::assertSame(2, $list->indexAt(new MouseMsg(0, 2, MouseAction::Press, MouseButton::Left), $area));
        self::assertNull(
            $list->indexAt(new MouseMsg(0, 9, MouseAction::Press, MouseButton::Left), $area),
            'Below the area entirely',
        );
    }

    #[Test]
    public function implementsFocusable(): void
    {
        self::assertInstanceOf(Focusable::class, ListView::new([]));
    }

    #[Test]
    public function constraintReturnsFlex(): void
    {
        $list = ListView::new([]);
        self::assertSame(1, $list->sizing()->rows->flex);
    }

    #[Test]
    public function cursorStartsAtZero(): void
    {
        $list = ListView::fromStrings(['A', 'B', 'C']);
        self::assertSame(0, $list->cursor());
    }

    #[Test]
    public function arrowDownMovesCursor(): void
    {
        $list = ListView::fromStrings(['A', 'B', 'C']);
        $area = Rect::fromSize(40, 10);
        $result = $list->update(new KeyMsg(Key::Down), $area);
        self::assertSame(1, $result->model->cursor());
    }

    #[Test]
    public function arrowDownDoesNotMovePastLast(): void
    {
        $list = ListView::fromStrings(['A', 'B']);
        $area = Rect::fromSize(40, 10);
        $result = $list->update(new KeyMsg(Key::Down), $area);
        $result = $result->model->update(new KeyMsg(Key::Down), $area);
        self::assertSame(1, $result->model->cursor());
    }

    #[Test]
    public function arrowUpMovesCursor(): void
    {
        $list = ListView::fromStrings(['A', 'B', 'C']);
        $area = Rect::fromSize(40, 10);
        $result = $list->update(new KeyMsg(Key::Down), $area);
        $result = $result->model->update(new KeyMsg(Key::Up), $area);
        self::assertSame(0, $result->model->cursor());
    }

    #[Test]
    public function arrowUpAtTopReturnsSelf(): void
    {
        $list = ListView::fromStrings(['A', 'B']);
        $area = Rect::fromSize(40, 10);
        $result = $list->update(new KeyMsg(Key::Up), $area);
        self::assertSame($list, $result->model);
    }

    #[Test]
    public function enterSelectsItem(): void
    {
        $list = ListView::fromStrings(['A', 'B', 'C']);
        $area = Rect::fromSize(40, 10);
        $result = $list->update(new KeyMsg(Key::Down), $area);
        $result = $result->model->update(new KeyMsg(Key::Enter), $area);
        self::assertSame(1, $result->model->selectedIndex());
    }

    #[Test]
    public function selectedValue(): void
    {
        $list = ListView::fromStrings(['Apple', 'Banana', 'Cherry']);
        $area = Rect::fromSize(40, 10);
        $result = $list->update(new KeyMsg(Key::Down), $area);
        $result = $result->model->update(new KeyMsg(Key::Enter), $area);
        $item = $result->model->selectedItem();
        self::assertSame('Banana', $item->title());
    }

    #[Test]
    public function selectedValueWithNoSelection(): void
    {
        $list = ListView::fromStrings(['A', 'B']);
        self::assertNull($list->selectedItem());
    }

    #[Test]
    public function homeMovesCursorToFirst(): void
    {
        $list = ListView::fromStrings(['A', 'B', 'C']);
        $area = Rect::fromSize(40, 10);
        $result = $list->update(new KeyMsg(Key::Down), $area);
        $result = $result->model->update(new KeyMsg(Key::Down), $area);
        $result = $result->model->update(new KeyMsg(Key::Home), $area);
        self::assertSame(0, $result->model->cursor());
    }

    #[Test]
    public function endMovesCursorToLast(): void
    {
        $list = ListView::fromStrings(['A', 'B', 'C']);
        $area = Rect::fromSize(40, 10);
        $result = $list->update(new KeyMsg(Key::End), $area);
        self::assertSame(2, $result->model->cursor());
    }

    #[Test]
    public function pageDownMovesCursorByPageSize(): void
    {
        $items = array_map(fn (int $index) => "Item $index", range(0, 19));
        $list = ListView::fromStrings($items);
        $area = Rect::fromSize(40, 5);

        $result = $list->update(new KeyMsg(Key::PageDown), $area);
        self::assertSame(5, $result->model->cursor());
    }

    #[Test]
    public function everyPageDownMovesAnotherWholePage(): void
    {
        $items = array_map(fn (int $index) => "Item $index", range(0, 19));
        $list = ListView::fromStrings($items);
        $area = Rect::fromSize(40, 5);

        $first = $list->update(new KeyMsg(Key::PageDown), $area)->model;
        $second = $first->update(new KeyMsg(Key::PageDown), $area)->model;
        $third = $second->update(new KeyMsg(Key::PageDown), $area)->model;

        self::assertSame([5, 10, 15], [$first->cursor(), $second->cursor(), $third->cursor()]);
    }

    #[Test]
    public function aPageIsAViewportOfRowsEvenWhenItemsAreTaller(): void
    {
        // Ten two-row items in a seven-row viewport: a page holds three of them.
        $items = array_map(
            static fn (int $index): SimpleListItem => new SimpleListItem("Item $index", "About item $index"),
            range(0, 9),
        );
        $list = ListView::new($items);
        $area = Rect::fromSize(30, 7);

        $down = $list->update(new KeyMsg(Key::PageDown), $area)->model;

        self::assertSame(3, $down->cursor(), 'Not seven, which would be two pages of rows');
    }

    #[Test]
    public function pagingDownNeverJumpsOverAnItemThatWasNotOnScreen(): void
    {
        // Mixed heights: every third description wraps onto a second line.
        $items = array_map(
            static fn (int $index): SimpleListItem => new SimpleListItem(
                "Item $index",
                $index % 3 === 0 ? "A description of item $index long enough to wrap" : "About $index",
            ),
            range(0, 19),
        );
        $area = Rect::fromSize(24, 9);
        $list = ListView::new($items);

        for ($page = 0; $page < 10; ++$page) {
            $onScreen = $this->titlesOnScreen($list, $area);
            $next = $list->update(new KeyMsg(Key::PageDown), $area)->model;

            if ($next->cursor() === $list->cursor()) {
                break;
            }

            self::assertContains(
                'Item ' . $next->cursor(),
                $onScreen,
                'A page lands on an item the reader had already seen, so nothing is passed over unseen',
            );

            $list = $next;
        }
    }

    #[Test]
    public function shiftDownMovesCursorByPageSizeToo(): void
    {
        $items = array_map(fn (int $index) => "Item $index", range(0, 19));
        $list = ListView::fromStrings($items);

        $result = $list->update(new KeyMsg(Key::Down, shift: true), Rect::fromSize(40, 5));

        self::assertSame(5, $result->model->cursor(), 'The same page the PageDown key moves');
    }

    #[Test]
    public function pageUpMovesCursorByPageSize(): void
    {
        $items = array_map(fn (int $index) => "Item $index", range(0, 19));
        $list = ListView::fromStrings($items);
        $area = Rect::fromSize(40, 5);

        $result = $list->update(new KeyMsg(Key::End), $area);
        $result = $result->model->update(new KeyMsg(Key::PageUp), $area);
        self::assertSame(14, $result->model->cursor());
    }

    #[Test]
    public function mouseClickSelectsItem(): void
    {
        $list = ListView::fromStrings(['A', 'B', 'C']);
        $area = new Rect(0, 0, 40, 5);

        $mouseMessage = new MouseMsg(5, 2, MouseAction::Press, MouseButton::Left);
        $result = $list->update($mouseMessage, $area);
        self::assertSame(2, $result->model->cursor());
        self::assertSame(2, $result->model->selectedIndex());
    }

    #[Test]
    public function mouseClickOutsideDoesNothing(): void
    {
        $list = ListView::fromStrings(['A', 'B', 'C']);
        $area = new Rect(0, 0, 40, 5);

        $mouseMessage = new MouseMsg(5, 10, MouseAction::Press, MouseButton::Left);
        $result = $list->update($mouseMessage, $area);
        self::assertSame($list, $result->model);
    }

    #[Test]
    public function mouseWheelScrolls(): void
    {
        $items = array_map(fn (int $index) => "Item $index", range(0, 19));
        $list = ListView::fromStrings($items);
        $area = new Rect(0, 0, 40, 5);

        $wheelDown = new MouseMsg(5, 2, MouseAction::WheelDown, MouseButton::None);
        $result = $list->update($wheelDown, $area);
        self::assertGreaterThan(0, $result->model->scrollOffset());
    }

    #[Test]
    public function mouseWheelUpScrolls(): void
    {
        $items = array_map(fn (int $index) => "Item $index", range(0, 19));
        $list = ListView::fromStrings($items);
        $area = new Rect(0, 0, 40, 5);

        $wheelDown = new MouseMsg(5, 2, MouseAction::WheelDown, MouseButton::None);
        $result = $list->update($wheelDown, $area);
        $scrolledDown = $result->model;

        $wheelUp = new MouseMsg(5, 2, MouseAction::WheelUp, MouseButton::None);
        $result = $scrolledDown->update($wheelUp, $area);
        self::assertLessThan($scrolledDown->scrollOffset(), $result->model->scrollOffset());
    }

    #[Test]
    public function emptyListHandlesKeyGracefully(): void
    {
        $list = ListView::new([]);
        $area = Rect::fromSize(40, 10);
        $result = $list->update(new KeyMsg(Key::Down), $area);
        self::assertSame($list, $result->model);
    }

    #[Test]
    public function emptyListRenderDoesNotCrash(): void
    {
        $list = ListView::new([]);
        $frame = new Frame(20, 5);
        $list->render($frame, Rect::fromSize(20, 5), new RenderContext(Theme::dark()));
        self::assertTrue(true);
    }

    #[Test]
    public function rendersCursorStyleForCursor(): void
    {
        $list = ListView::fromStrings(['Alpha', 'Beta']);
        $frame = new Frame(40, 5);
        $area = new Rect(0, 0, 40, 5);
        $theme = Theme::dark();
        $list->render($frame, $area, RenderContext::focused($theme));

        self::assertSame('A', $frame->characters[0]);
        self::assertSame($theme->cursorStyle->backgroundValue(), $frame->backgrounds[0]);
    }

    #[Test]
    public function rendersItemDescription(): void
    {
        $items = [new SimpleListItem('Title', 'Description')];
        $list = ListView::new($items);
        $frame = new Frame(40, 5);
        $area = new Rect(0, 0, 40, 5);
        $list->render($frame, $area, new RenderContext(Theme::dark()));

        $secondRow = '';
        for ($column = 0; $column < 20; ++$column) {
            $secondRow .= $frame->characters[40 + $column];
        }
        self::assertStringContainsString('Description', $secondRow);
    }

    #[Test]
    public function fromStringsCreatesSimpleListItems(): void
    {
        $list = ListView::fromStrings(['A', 'B', 'C']);
        self::assertSame(3, $list->itemCount());
    }

    #[Test]
    public function withListItemsAcceptsCustomItems(): void
    {
        $items = [
            new SimpleListItem('First', 'Description 1'),
            new SimpleListItem('Second', 'Description 2'),
        ];
        $list = ListView::new($items);
        self::assertSame(2, $list->itemCount());
    }

    #[Test]
    public function rendersScrollbarWhenItemsOverflow(): void
    {
        $items = [];
        for ($index = 0; $index < 20; ++$index) {
            $items[] = new SimpleListItem("Item $index");
        }
        $list = ListView::new($items);
        $frame = new Frame(30, 5);
        $area = new Rect(0, 0, 30, 5);
        $list->render($frame, $area, new RenderContext(Theme::dark()));

        $scrollbarCol = 29;
        $hasThumb = false;
        for ($row = 0; $row < 5; ++$row) {
            if ($frame->characters[$row * 30 + $scrollbarCol] === Scrollbar::THUMB) {
                $hasThumb = true;
                break;
            }
        }
        self::assertTrue($hasThumb, 'Should have scrollbar thumb');
    }

    #[Test]
    public function noScrollbarWhenAllItemsFit(): void
    {
        $list = ListView::fromStrings(['A', 'B']);
        $frame = new Frame(30, 5);
        $area = new Rect(0, 0, 30, 5);
        $list->render($frame, $area, new RenderContext(Theme::dark()));

        self::assertSame(' ', $frame->characters[29]);
    }

    // --- L29: DecrementInteger — selected starts at -1, not -2 ---

    #[Test]
    public function selectedStartsAtMinusOne(): void
    {
        $list = ListView::fromStrings(['A', 'B']);
        self::assertNull($list->selectedIndex());
    }

    // --- L94: MatchArmRemoval — empty list update returns self unchanged ---

    #[Test]
    public function emptyListUpdateWithMouseReturnsUnchanged(): void
    {
        $list = ListView::new([]);
        $area = Rect::fromSize(40, 10);
        $mouse = new MouseMsg(5, 2, MouseAction::Press, MouseButton::Left);
        $result = $list->update($mouse, $area);
        self::assertSame($list, $result->model);
    }

    // --- L104-105: render bounds — zero width, zero height ---

    #[Test]
    public function renderDoesNothingWithZeroWidth(): void
    {
        $list = ListView::fromStrings(['A', 'B']);
        $frame = new Frame(20, 5);
        $area = new Rect(0, 0, 0, 5);
        $list->render($frame, $area, new RenderContext(Theme::dark()));
        self::assertSame(' ', $frame->characters[0]);
    }

    #[Test]
    public function renderDoesNothingWithZeroHeight(): void
    {
        $list = ListView::fromStrings(['A', 'B']);
        $frame = new Frame(20, 5);
        $area = new Rect(0, 0, 20, 0);
        $list->render($frame, $area, new RenderContext(Theme::dark()));
        self::assertSame(' ', $frame->characters[0]);
    }

    // --- L118-127: renderY >= area->bottom() break, cursor identity, renderY += height ---

    #[Test]
    public function renderStopsAtAreaBottom(): void
    {
        $items = array_map(fn (int $index) => "Item $index", range(0, 9));
        $list = ListView::fromStrings($items);
        $frame = new Frame(20, 3);
        $area = new Rect(0, 0, 20, 3);
        $list->render($frame, $area, new RenderContext(Theme::dark()));

        $row0 = '';
        for ($column = 0; $column < 6; ++$column) {
            $row0 .= $frame->characters[$column];
        }
        self::assertStringContainsString('Item 0', $row0);

        $row2 = '';
        for ($column = 0; $column < 6; ++$column) {
            $row2 .= $frame->characters[2 * 20 + $column];
        }
        self::assertStringContainsString('Item 2', $row2);
    }

    #[Test]
    public function renderAdvancesRenderYByItemHeight(): void
    {
        $items = [
            new SimpleListItem('Title A', 'Desc A'),
            new SimpleListItem('Title B'),
        ];
        $list = ListView::new($items);
        $frame = new Frame(20, 5);
        $area = new Rect(0, 0, 20, 5);
        $list->render($frame, $area, new RenderContext(Theme::dark()));

        $row2 = '';
        for ($column = 0; $column < 7; ++$column) {
            $row2 .= $frame->characters[2 * 20 + $column];
        }
        self::assertStringContainsString('Title B', $row2);
    }

    // --- L150-151: MatchArmRemoval — style branches in renderItem ---

    #[Test]
    public function selectedItemGetsAccentStyle(): void
    {
        $list = ListView::fromStrings(['A', 'B', 'C']);
        $area = Rect::fromSize(20, 5);
        $result = $list->update(new KeyMsg(Key::Down), $area);
        $result = $result->model->update(new KeyMsg(Key::Enter), $area);
        $listWithSelection = $result->model;

        $result2 = $listWithSelection->update(new KeyMsg(Key::Down), $area);
        $listCursorOnC = $result2->model;

        $frame = new Frame(20, 5);
        $theme = Theme::dark();
        $listCursorOnC->render($frame, $area, new RenderContext($theme));

        $selectedRowIndex = 1 * 20;
        self::assertSame($theme->accent->foregroundValue(), $frame->foregrounds[$selectedRowIndex]);
    }

    #[Test]
    public function focusedNonCursorNonSelectedItemGetsBaseStyle(): void
    {
        $list = ListView::fromStrings(['A', 'B', 'C']);
        $frame = new Frame(20, 5);
        $area = new Rect(0, 0, 20, 5);
        $theme = Theme::dark();
        $list->render($frame, $area, RenderContext::focused($theme));

        $secondRowIndex = 1 * 20;
        self::assertSame($theme->base->foregroundValue(), $frame->foregrounds[$secondRowIndex]);
    }

    #[Test]
    public function unfocusedItemGetsDimStyle(): void
    {
        $list = ListView::fromStrings(['A', 'B', 'C']);
        $frame = new Frame(20, 5);
        $area = new Rect(0, 0, 20, 5);
        $theme = Theme::dark();
        $list->render($frame, $area, new RenderContext($theme));

        // Row 0 carries the cursor highlight; rows past it are plain dim text.
        self::assertSame($theme->dim->foregroundValue(), $frame->foregrounds[1 * 20]);
    }

    #[Test]
    public function cursorAndFocusedGetsCursorStyle(): void
    {
        $list = ListView::fromStrings(['A', 'B']);
        $frame = new Frame(20, 5);
        $area = new Rect(0, 0, 20, 5);
        $theme = Theme::dark();
        $list->render($frame, $area, RenderContext::focused($theme));

        self::assertSame($theme->cursorStyle->foregroundValue(), $frame->foregrounds[0]);
        self::assertSame($theme->cursorStyle->backgroundValue(), $frame->backgrounds[0]);
    }

    // --- L150: LogicalAnd — isCursor && focused ---

    #[Test]
    public function cursorWithoutFocusDoesNotGetCursorStyle(): void
    {
        $list = ListView::fromStrings(['A', 'B']);
        $frame = new Frame(20, 5);
        $area = new Rect(0, 0, 20, 5);
        $theme = Theme::dark();
        $list->render($frame, $area, new RenderContext($theme));

        self::assertNotSame($theme->cursorStyle->backgroundValue(), $frame->backgrounds[0]);
    }

    #[Test]
    public function spaceSelectsItem(): void
    {
        $list = ListView::fromStrings(['A', 'B', 'C']);
        $area = Rect::fromSize(40, 10);
        $result = $list->update(new KeyMsg(' '), $area);
        self::assertSame(0, $result->model->selectedIndex());
    }

    #[Test]
    public function upAtZeroReturnsSameInstance(): void
    {
        $list = ListView::fromStrings(['A', 'B']);
        $area = Rect::fromSize(40, 10);
        $result = $list->update(new KeyMsg(Key::Up), $area);
        self::assertSame($list, $result->model);
    }

    #[Test]
    public function downAtLastReturnsSameInstance(): void
    {
        $list = ListView::fromStrings(['A']);
        $area = Rect::fromSize(40, 10);
        $result = $list->update(new KeyMsg(Key::Down), $area);
        self::assertSame($list, $result->model);
    }

    #[Test]
    public function homeAtTopReturnsSameInstance(): void
    {
        $list = ListView::fromStrings(['A', 'B']);
        $area = Rect::fromSize(40, 10);
        $result = $list->update(new KeyMsg(Key::Home), $area);
        self::assertSame($list, $result->model);
    }

    #[Test]
    public function endAtBottomReturnsSameInstance(): void
    {
        $list = ListView::fromStrings(['A']);
        $area = Rect::fromSize(40, 10);
        $result = $list->update(new KeyMsg(Key::End), $area);
        self::assertSame($list, $result->model);
    }

    #[Test]
    public function pageDownClampsToLastItem(): void
    {
        $list = ListView::fromStrings(['A', 'B', 'C']);
        $area = Rect::fromSize(40, 10);
        $result = $list->update(new KeyMsg(Key::PageDown), $area);
        self::assertSame(2, $result->model->cursor());
    }

    #[Test]
    public function pageUpClampsToFirstItem(): void
    {
        $list = ListView::fromStrings(['A', 'B', 'C']);
        $area = Rect::fromSize(40, 10);
        $result = $list->update(new KeyMsg(Key::Down), $area);
        $result = $result->model->update(new KeyMsg(Key::PageUp), $area);
        self::assertSame(0, $result->model->cursor());
    }

    #[Test]
    public function pageUpAtTopReturnsSameInstance(): void
    {
        $list = ListView::fromStrings(['A', 'B']);
        $area = Rect::fromSize(40, 10);
        $result = $list->update(new KeyMsg(Key::PageUp), $area);
        self::assertSame($list, $result->model);
    }

    #[Test]
    public function pageDownAtBottomReturnsSameInstance(): void
    {
        $list = ListView::fromStrings(['A']);
        $area = Rect::fromSize(40, 10);
        $result = $list->update(new KeyMsg(Key::PageDown), $area);
        self::assertSame($list, $result->model);
    }

    #[Test]
    public function unrecognizedKeyReturnsSameInstance(): void
    {
        $list = ListView::fromStrings(['A', 'B']);
        $area = Rect::fromSize(40, 10);
        $result = $list->update(new KeyMsg('x'), $area);
        self::assertSame($list, $result->model);
    }

    // --- L160-178: scroll/ensureCursorVisible math ---

    #[Test]
    public function scrollsDownWhenCursorExceedsViewport(): void
    {
        $items = array_map(fn (int $index) => "Item $index", range(0, 9));
        $list = ListView::fromStrings($items);
        $area = Rect::fromSize(40, 3);

        $current = $list;
        for ($step = 0; $step < 5; ++$step) {
            $result = $current->update(new KeyMsg(Key::Down), $area);
            $current = $result->model;
        }

        self::assertSame(5, $current->cursor());
        self::assertGreaterThan(0, $current->scrollOffset());
        self::assertSame(3, $current->scrollOffset());
    }

    #[Test]
    public function scrollsUpWhenCursorMovesAboveViewport(): void
    {
        $items = array_map(fn (int $index) => "Item $index", range(0, 9));
        $list = ListView::fromStrings($items);
        $area = Rect::fromSize(40, 3);

        $result = $list->update(new KeyMsg(Key::End), $area);
        $current = $result->model;
        self::assertSame(9, $current->cursor());
        self::assertSame(7, $current->scrollOffset());

        $result = $current->update(new KeyMsg(Key::Home), $area);
        $current = $result->model;
        self::assertSame(0, $current->cursor());
        self::assertSame(0, $current->scrollOffset());
    }

    #[Test]
    public function ensureCursorVisibleDoesNotChangeWhenAlreadyVisible(): void
    {
        $items = array_map(fn (int $index) => "Item $index", range(0, 9));
        $list = ListView::fromStrings($items);
        $area = Rect::fromSize(40, 5);

        $result = $list->update(new KeyMsg(Key::Down), $area);
        self::assertSame(0, $result->model->scrollOffset());
    }

    #[Test]
    public function ensureCursorVisibleNoOpDoesNotChangeScrollOffset(): void
    {
        $items = array_map(fn (int $index) => "Item $index", range(0, 9));
        $list = ListView::fromStrings($items);
        $area = Rect::fromSize(40, 10);

        $result = $list->update(new KeyMsg(Key::Down), $area);
        self::assertSame(0, $result->model->scrollOffset(), 'scrollOffset must stay 0 when cursor moves into an already-visible row');
        self::assertSame(1, $result->model->cursor());
    }

    // --- L196-211: mouse offset calculations ---

    #[Test]
    public function mouseClickWithOffsetArea(): void
    {
        $list = ListView::fromStrings(['A', 'B', 'C']);
        $area = new Rect(5, 10, 20, 5);

        $mouse = new MouseMsg(10, 12, MouseAction::Press, MouseButton::Left);
        $result = $list->update($mouse, $area);
        self::assertSame(2, $result->model->cursor());
        self::assertSame(2, $result->model->selectedIndex());
    }

    #[Test]
    public function mouseClickOnFirstItemAtOffset(): void
    {
        $list = ListView::fromStrings(['A', 'B', 'C']);
        $area = new Rect(5, 10, 20, 5);

        $mouse = new MouseMsg(10, 10, MouseAction::Press, MouseButton::Left);
        $result = $list->update($mouse, $area);
        self::assertSame(0, $result->model->cursor());
    }

    #[Test]
    public function theWheelScrollsToThePaddedBottomLikeEveryOtherWidget(): void
    {
        $items = array_map(fn (int $index) => "Item $index", range(0, 9));
        $list = ListView::fromStrings($items);
        $area = Rect::fromSize(40, 3);

        $wheelDown = new MouseMsg(5, 0, MouseAction::WheelDown, MouseButton::None);
        $result = $list;
        for ($step = 0; $step < 12; ++$step) {
            $result = $result->update($wheelDown, $area)->model;
        }

        self::assertSame(8, $result->scrollOffset(), 'the blank line under the last row says the list ends here');
    }

    #[Test]
    public function mouseWheelDownClampsToMaxOffset(): void
    {
        $list = ListView::fromStrings(['A', 'B', 'C']);
        $area = new Rect(0, 0, 20, 2);

        $wheelDown = new MouseMsg(5, 0, MouseAction::WheelDown, MouseButton::None);
        $result = $list->update($wheelDown, $area);
        self::assertSame(1, $result->model->scrollOffset());

        $result = $result->model->update($wheelDown, $area);
        self::assertSame(2, $result->model->scrollOffset());

        $result = $result->model->update($wheelDown, $area);
        self::assertSame(2, $result->model->scrollOffset());
    }

    #[Test]
    public function mouseWheelUpClampsToZero(): void
    {
        $list = ListView::fromStrings(['A', 'B', 'C']);
        $area = new Rect(0, 0, 20, 5);

        $wheelUp = new MouseMsg(5, 0, MouseAction::WheelUp, MouseButton::None);
        $result = $list->update($wheelUp, $area);
        self::assertSame(0, $result->model->scrollOffset());
    }

    #[Test]
    public function mouseWheelOutsideAreaDoesNothing(): void
    {
        $list = ListView::fromStrings(['A', 'B', 'C']);
        $area = new Rect(0, 0, 20, 3);

        $wheelDown = new MouseMsg(5, 10, MouseAction::WheelDown, MouseButton::None);
        $result = $list->update($wheelDown, $area);
        self::assertSame($list, $result->model);
    }

    // --- L223-272: rendering with scroll offset ---

    #[Test]
    public function renderWithScrollOffsetSkipsItems(): void
    {
        $items = array_map(fn (int $index) => "Item $index", range(0, 9));
        $list = ListView::fromStrings($items);
        $area = Rect::fromSize(20, 3);

        $current = $list;
        for ($step = 0; $step < 5; ++$step) {
            $result = $current->update(new KeyMsg(Key::Down), $area);
            $current = $result->model;
        }

        $frame = new Frame(20, 3);
        $current->render($frame, $area, new RenderContext(Theme::dark()));

        $row0 = '';
        for ($column = 0; $column < 6; ++$column) {
            $row0 .= $frame->characters[$column];
        }
        self::assertStringContainsString('Item 3', $row0);
    }

    #[Test]
    public function renderWithMultiHeightItems(): void
    {
        $items = [
            new SimpleListItem('Title A', 'Desc A'),
            new SimpleListItem('Title B', 'Desc B'),
            new SimpleListItem('Title C'),
        ];
        $list = ListView::new($items);
        $frame = new Frame(20, 6);
        $area = new Rect(0, 0, 20, 6);
        $list->render($frame, $area, new RenderContext(Theme::dark()));

        $row0 = '';
        for ($column = 0; $column < 7; ++$column) {
            $row0 .= $frame->characters[$column];
        }
        self::assertStringContainsString('Title A', $row0);

        $row1 = '';
        for ($column = 0; $column < 8; ++$column) {
            $row1 .= $frame->characters[20 + $column];
        }
        self::assertStringContainsString('Desc A', $row1);

        $row2 = '';
        for ($column = 0; $column < 7; ++$column) {
            $row2 .= $frame->characters[2 * 20 + $column];
        }
        self::assertStringContainsString('Title B', $row2);

        $row4 = '';
        for ($column = 0; $column < 7; ++$column) {
            $row4 .= $frame->characters[4 * 20 + $column];
        }
        self::assertStringContainsString('Title C', $row4);
    }

    #[Test]
    public function itemIndexAtYWithScrolledMultiHeightItems(): void
    {
        $items = [
            new SimpleListItem('Title A', 'Desc A'),
            new SimpleListItem('Title B', 'Desc B'),
            new SimpleListItem('Title C'),
        ];
        $list = ListView::new($items);
        $area = new Rect(0, 0, 20, 3);

        $wheelDown = new MouseMsg(5, 0, MouseAction::WheelDown, MouseButton::None);
        $result = $list->update($wheelDown, $area);
        $scrolled = $result->model;
        self::assertSame(1, $scrolled->scrollOffset());

        $click = new MouseMsg(5, 1, MouseAction::Press, MouseButton::Left);
        $result = $scrolled->update($click, $area);
        self::assertSame(1, $result->model->cursor());
    }

    #[Test]
    public function mouseClickBeyondLastItemDoesNothing(): void
    {
        $list = ListView::fromStrings(['A', 'B']);
        $area = new Rect(0, 0, 20, 10);

        $mouse = new MouseMsg(5, 5, MouseAction::Press, MouseButton::Left);
        $result = $list->update($mouse, $area);
        self::assertSame($list, $result->model);
    }

    // --- ensureCursorVisible with multi-height items ---

    #[Test]
    public function scrollsDownWithMultiHeightItems(): void
    {
        $items = [
            new SimpleListItem('A', 'Desc A'),
            new SimpleListItem('B', 'Desc B'),
            new SimpleListItem('C', 'Desc C'),
        ];
        $list = ListView::new($items);
        $area = Rect::fromSize(20, 3);

        $result = $list->update(new KeyMsg(Key::Down), $area);
        self::assertSame(1, $result->model->cursor());
        self::assertGreaterThanOrEqual(0, $result->model->scrollOffset());

        $result = $result->model->update(new KeyMsg(Key::Down), $area);
        self::assertSame(2, $result->model->cursor());
        self::assertGreaterThan(0, $result->model->scrollOffset());
    }

    #[Test]
    public function scrollOffsetAdjustsToShowCursorBottom(): void
    {
        $items = [
            new SimpleListItem('A', 'Desc A'),
            new SimpleListItem('B', 'Desc B'),
            new SimpleListItem('C', 'Desc C'),
            new SimpleListItem('D', 'Desc D'),
        ];
        $list = ListView::new($items);
        $area = Rect::fromSize(20, 4);

        $result = $list->update(new KeyMsg(Key::End), $area);
        $model = $result->model;
        self::assertSame(3, $model->cursor());

        $cursorBottom = 4 * 2;
        $expectedOffset = $cursorBottom - $area->height;
        self::assertSame($expectedOffset, $model->scrollOffset());
    }

    // --- Render with area at non-zero origin ---

    #[Test]
    public function renderAtNonZeroOrigin(): void
    {
        $list = ListView::fromStrings(['Hello', 'World']);
        $frame = new Frame(30, 15);
        $area = new Rect(5, 10, 20, 5);
        $list->render($frame, $area, new RenderContext(Theme::dark()));

        $row10col5 = '';
        for ($column = 5; $column < 10; ++$column) {
            $row10col5 .= $frame->characters[10 * 30 + $column];
        }
        self::assertStringContainsString('Hello', $row10col5);

        $row11col5 = '';
        for ($column = 5; $column < 10; ++$column) {
            $row11col5 .= $frame->characters[11 * 30 + $column];
        }
        self::assertStringContainsString('World', $row11col5);
    }

    // --- Description rendering at correct offset ---

    #[Test]
    public function descriptionRenderedAtXPlusTwoOffset(): void
    {
        $items = [new SimpleListItem('Title', 'Desc')];
        $list = ListView::new($items);
        $frame = new Frame(20, 5);
        $area = new Rect(0, 0, 20, 5);
        $list->render($frame, $area, new RenderContext(Theme::dark()));

        self::assertSame(' ', $frame->characters[20 + 0]);
        self::assertSame(' ', $frame->characters[20 + 1]);
        self::assertSame('D', $frame->characters[20 + 2]);
        self::assertSame('e', $frame->characters[20 + 3]);
    }

    #[Test]
    public function scrollbarRendersWithMixedHeightItems(): void
    {
        $items = [];
        for ($index = 0; $index < 5; ++$index) {
            $items[] = new SimpleListItem("Item $index", "Desc $index");
        }
        $list = ListView::new($items);
        $frame = new Frame(30, 5);
        $area = new Rect(0, 0, 30, 5);
        $list->render($frame, $area, new RenderContext(Theme::dark()));

        $scrollbarCol = 29;
        $hasThumb = false;
        for ($row = 0; $row < 5; ++$row) {
            if ($frame->characters[$row * 30 + $scrollbarCol] === Scrollbar::THUMB) {
                $hasThumb = true;
                break;
            }
        }
        self::assertTrue($hasThumb, 'Should have scrollbar for 10 rows in 5-row viewport');
    }

    // --- Exact scroll offset verification ---

    #[Test]
    public function scrollOffsetExactlyMatchesCursorMinusViewport(): void
    {
        $items = array_map(fn (int $index) => "Item $index", range(0, 4));
        $list = ListView::fromStrings($items);
        $area = Rect::fromSize(20, 3);

        $result = $list->update(new KeyMsg(Key::Down), $area);
        $result = $result->model->update(new KeyMsg(Key::Down), $area);
        self::assertSame(2, $result->model->cursor());
        self::assertSame(0, $result->model->scrollOffset());

        $result = $result->model->update(new KeyMsg(Key::Down), $area);
        self::assertSame(3, $result->model->cursor());
        self::assertSame(1, $result->model->scrollOffset());

        $result = $result->model->update(new KeyMsg(Key::Down), $area);
        self::assertSame(4, $result->model->cursor());
        self::assertSame(2, $result->model->scrollOffset());
    }

    #[Test]
    public function scrollOffsetRetractsWhenCursorMovesUp(): void
    {
        $items = array_map(fn (int $index) => "Item $index", range(0, 9));
        $list = ListView::fromStrings($items);
        $area = Rect::fromSize(20, 3);

        $result = $list->update(new KeyMsg(Key::End), $area);
        self::assertSame(9, $result->model->cursor());
        self::assertSame(7, $result->model->scrollOffset());

        $result = $result->model->update(new KeyMsg(Key::Up), $area);
        self::assertSame(8, $result->model->cursor());
        self::assertSame(7, $result->model->scrollOffset());

        $result = $result->model->update(new KeyMsg(Key::Up), $area);
        self::assertSame(7, $result->model->cursor());
        self::assertSame(7, $result->model->scrollOffset());

        $result = $result->model->update(new KeyMsg(Key::Up), $area);
        self::assertSame(6, $result->model->cursor());
        self::assertSame(6, $result->model->scrollOffset());
    }

    // --- Mouse wheel exact offset values ---

    #[Test]
    public function mouseWheelDownIncrementsByOne(): void
    {
        $items = array_map(fn (int $index) => "Item $index", range(0, 19));
        $list = ListView::fromStrings($items);
        $area = new Rect(0, 0, 20, 5);

        $wheelDown = new MouseMsg(5, 2, MouseAction::WheelDown, MouseButton::None);
        $result = $list->update($wheelDown, $area);
        self::assertSame(1, $result->model->scrollOffset());
    }

    #[Test]
    public function mouseWheelUpDecrementsByOne(): void
    {
        $items = array_map(fn (int $index) => "Item $index", range(0, 19));
        $list = ListView::fromStrings($items);
        $area = new Rect(0, 0, 20, 5);

        $wheelDown = new MouseMsg(5, 2, MouseAction::WheelDown, MouseButton::None);
        $result = $list->update($wheelDown, $area);
        $result = $result->model->update($wheelDown, $area);
        self::assertSame(2, $result->model->scrollOffset());

        $wheelUp = new MouseMsg(5, 2, MouseAction::WheelUp, MouseButton::None);
        $result = $result->model->update($wheelUp, $area);
        self::assertSame(1, $result->model->scrollOffset());
    }

    #[Test]
    public function repeatedWheelingStopsAtThePaddedBottomAndNeverPastIt(): void
    {
        $list = ListView::fromStrings(['A', 'B', 'C', 'D', 'E']);
        $area = new Rect(0, 0, 20, 3);

        $wheelDown = new MouseMsg(5, 0, MouseAction::WheelDown, MouseButton::None);
        $current = $list;
        for ($step = 0; $step < 10; ++$step) {
            $result = $current->update($wheelDown, $area);
            $current = $result->model;
        }
        self::assertSame(3, $current->scrollOffset());
    }

    // --- itemIndexAtY — edge: click exactly at boundary of multi-height item ---

    #[Test]
    public function mouseClickOnDescriptionLineSelectsCorrectItem(): void
    {
        $items = [
            new SimpleListItem('Title A', 'Desc A'),
            new SimpleListItem('Title B'),
        ];
        $list = ListView::new($items);
        $area = new Rect(0, 0, 20, 5);

        $clickOnDesc = new MouseMsg(5, 1, MouseAction::Press, MouseButton::Left);
        $result = $list->update($clickOnDesc, $area);
        self::assertSame(0, $result->model->cursor());
        self::assertSame(0, $result->model->selectedIndex());
    }

    #[Test]
    public function mouseClickOnSecondItemAfterMultiHeight(): void
    {
        $items = [
            new SimpleListItem('Title A', 'Desc A'),
            new SimpleListItem('Title B'),
        ];
        $list = ListView::new($items);
        $area = new Rect(0, 0, 20, 5);

        $clickOnB = new MouseMsg(5, 2, MouseAction::Press, MouseButton::Left);
        $result = $list->update($clickOnB, $area);
        self::assertSame(1, $result->model->cursor());
        self::assertSame(1, $result->model->selectedIndex());
    }

    // --- render: description empty string not rendered ---

    #[Test]
    public function noDescriptionLineWhenDescriptionEmpty(): void
    {
        $items = [
            new SimpleListItem('Title A'),
            new SimpleListItem('Title B'),
        ];
        $list = ListView::new($items);
        $frame = new Frame(20, 5);
        $area = new Rect(0, 0, 20, 5);
        $list->render($frame, $area, new RenderContext(Theme::dark()));

        $row1 = '';
        for ($column = 0; $column < 7; ++$column) {
            $row1 .= $frame->characters[20 + $column];
        }
        self::assertStringContainsString('Title B', $row1);
    }

    // --- L118: GreaterThanOrEqualTo — renderY exactly equals bottom should NOT render ---

    #[Test]
    public function renderDoesNotRenderItemAtExactAreaBottom(): void
    {
        $list = ListView::fromStrings(['A', 'B', 'C', 'D']);
        $frame = new Frame(20, 3);
        $area = new Rect(0, 0, 20, 3);
        $list->render($frame, $area, new RenderContext(Theme::dark()));

        $row0 = $frame->characters[0];
        $row1 = $frame->characters[20];
        $row2 = $frame->characters[40];
        self::assertSame('A', $row0);
        self::assertSame('B', $row1);
        self::assertSame('C', $row2);
    }

    // --- L229: itemIndexAtY boundary — click at exact last row of item ---

    #[Test]
    public function mouseClickAtExactLastRowOfMultiHeightItem(): void
    {
        $items = [
            new SimpleListItem('Title A', 'Desc A'),
            new SimpleListItem('Title B', 'Desc B'),
        ];
        $list = ListView::new($items);
        $area = new Rect(0, 0, 20, 5);

        $clickLastRowOfA = new MouseMsg(5, 1, MouseAction::Press, MouseButton::Left);
        $result = $list->update($clickLastRowOfA, $area);
        self::assertSame(0, $result->model->cursor());

        $clickFirstRowOfB = new MouseMsg(5, 2, MouseAction::Press, MouseButton::Left);
        $result = $list->update($clickFirstRowOfB, $area);
        self::assertSame(1, $result->model->cursor());
    }

    // --- L263/267/271: ensureCursorVisible exact boundary ---

    #[Test]
    public function ensureCursorVisibleAtExactBottomBoundary(): void
    {
        $items = array_map(fn (int $index) => "Item $index", range(0, 4));
        $list = ListView::fromStrings($items);
        $area = Rect::fromSize(20, 3);

        $result = $list->update(new KeyMsg(Key::Down), $area);
        $result = $result->model->update(new KeyMsg(Key::Down), $area);
        self::assertSame(2, $result->model->cursor());
        self::assertSame(0, $result->model->scrollOffset());
    }

    #[Test]
    public function ensureCursorVisibleScrollsJustEnoughDown(): void
    {
        $items = array_map(fn (int $index) => "Item $index", range(0, 5));
        $list = ListView::fromStrings($items);
        $area = Rect::fromSize(20, 3);

        $result = $list->update(new KeyMsg(Key::Down), $area);
        $result = $result->model->update(new KeyMsg(Key::Down), $area);
        $result = $result->model->update(new KeyMsg(Key::Down), $area);
        self::assertSame(3, $result->model->cursor());
        self::assertSame(1, $result->model->scrollOffset());
    }

    #[Test]
    public function ensureCursorVisibleScrollsJustEnoughUp(): void
    {
        $items = array_map(fn (int $index) => "Item $index", range(0, 5));
        $list = ListView::fromStrings($items);
        $area = Rect::fromSize(20, 3);

        $result = $list->update(new KeyMsg(Key::End), $area);
        self::assertSame(5, $result->model->cursor());
        self::assertSame(3, $result->model->scrollOffset());

        $result = $result->model->update(new KeyMsg(Key::Up), $area);
        $result = $result->model->update(new KeyMsg(Key::Up), $area);
        self::assertSame(3, $result->model->cursor());
        self::assertSame(3, $result->model->scrollOffset());

        $result = $result->model->update(new KeyMsg(Key::Up), $area);
        self::assertSame(2, $result->model->cursor());
        self::assertSame(2, $result->model->scrollOffset());
    }

    // --- L160: description rendered at x+2, y+1 exactly ---

    #[Test]
    public function descriptionAtExactXOffset(): void
    {
        $items = [new SimpleListItem('Title', 'Desc')];
        $list = ListView::new($items);
        $frame = new Frame(20, 5);
        $area = new Rect(3, 0, 17, 5);
        $list->render($frame, $area, new RenderContext(Theme::dark()));

        self::assertSame('D', $frame->characters[20 + 5]);
        self::assertSame(' ', $frame->characters[20 + 4]);
    }

    #[Test]
    public function descriptionAtExactYOffset(): void
    {
        $items = [new SimpleListItem('Title', 'Desc')];
        $list = ListView::new($items);
        $frame = new Frame(20, 5);
        $area = new Rect(0, 2, 20, 3);
        $list->render($frame, $area, new RenderContext(Theme::dark()));

        self::assertSame('T', $frame->characters[2 * 20 + 0]);
        self::assertSame('D', $frame->characters[3 * 20 + 2]);
        self::assertNotSame('D', $frame->characters[2 * 20 + 2]);
    }

    // --- L167: pageSize = max(1, area.height) ---

    #[Test]
    public function pageDownWithZeroHeightAreaUsesPageSizeOfOne(): void
    {
        $items = array_map(fn (int $index) => "Item $index", range(0, 5));
        $list = ListView::fromStrings($items);
        $area = new Rect(0, 0, 20, 1);

        $result = $list->update(new KeyMsg(Key::PageDown), $area);
        self::assertSame(1, $result->model->cursor());
    }

    // --- L113/114: render scroll offset skip with exact rowsConsumed boundary ---

    #[Test]
    public function renderScrollOffsetSkipsExactRows(): void
    {
        $items = [
            new SimpleListItem('A', 'DescA'),
            new SimpleListItem('B'),
            new SimpleListItem('C'),
        ];
        $list = ListView::new($items);
        $area = Rect::fromSize(20, 3);

        $result = $list->update(new KeyMsg(Key::End), $area);
        $model = $result->model;

        $frame = new Frame(20, 3);
        $model->render($frame, $area, new RenderContext(Theme::dark()));

        $row0 = $frame->characters[0];
        self::assertSame('B', $row0);
    }

    // --- render: foreach skips items before scroll offset ---

    /**
     * L94: MatchArmRemoval — $count === 0 early return.
     * Empty list receiving KeyMsg should return same instance, not fall through to handleKey.
     */
    #[Test]
    public function emptyListDoesNotHandleKeyMessages(): void
    {
        $list = ListView::fromStrings([]);
        $area = Rect::fromSize(20, 5);
        $result = $list->update(new KeyMsg(Key::Down), $area);
        self::assertSame($list, $result->model);
    }

    #[Test]
    public function emptyListEnterKeyDoesNotCrash(): void
    {
        $list = ListView::fromStrings([]);
        $area = Rect::fromSize(20, 5);
        $result = $list->update(new KeyMsg(Key::Enter), $area);
        self::assertSame($list, $result->model);
    }

    /**
     * L119/L120: >= vs > and break vs continue.
     * Items beyond area.bottom() must NOT be rendered. Verify the cell below the area remains blank.
     */
    #[Test]
    public function itemsAtExactAreaBottomAreNotRendered(): void
    {
        $list = ListView::fromStrings(['A', 'B', 'C', 'D', 'E']);
        $frame = new Frame(20, 4);
        $area = new Rect(0, 0, 20, 3);
        $list->render($frame, $area, new RenderContext(Theme::dark()));

        // Row 3 (index area.height) should be blank since it's beyond the area
        self::assertSame(' ', $frame->characters[3 * 20]);
        self::assertSame(0, $frame->foregrounds[3 * 20]);
    }

    /**
     * L120: break vs continue. If changed to continue, the loop would keep
     * iterating past bottom and render items below the visible area.
     * With break, it stops early. We verify by checking that a 5th item
     * in a 3-row area doesn't render at row 4.
     */
    #[Test]
    public function breakPreventsRenderingBeyondAreaBottom(): void
    {
        $list = ListView::fromStrings(['A', 'B', 'C', 'D', 'E']);
        $frame = new Frame(20, 6);
        $area = new Rect(0, 0, 20, 3);
        $list->render($frame, $area, new RenderContext(Theme::dark()));

        // With break: only A, B, C render. D and E don't.
        // With continue: D at row 3 and E at row 4 would still try to render.
        self::assertSame('A', $frame->characters[0]);
        self::assertSame('B', $frame->characters[20]);
        self::assertSame('C', $frame->characters[40]);
        // Row 3 should be blank (not 'D')
        self::assertNotSame('D', $frame->characters[60]);
    }

    /**
     * L168: max(1, area.height) vs max(0, area.height).
     * With height=0, pageSize should be 1 (from max(1,...)), not 0.
     * If pageSize=0, PageDown would move cursor by 0 (no movement).
     */
    #[Test]
    public function pageDownWithHeightZeroMovesExactlyOneItem(): void
    {
        $list = ListView::fromStrings(['A', 'B', 'C']);
        $area = new Rect(0, 0, 20, 0);
        $result = $list->update(new KeyMsg(Key::PageDown), $area);
        // max(1, 0) = 1, so cursor moves by 1
        // max(0, 0) = 0, so cursor wouldn't move (mutant)
        self::assertSame(1, $result->model->cursor());
    }

    /**
     * L174: End key guard — $this->cursor < $count - 1 vs $count - 2.
     * With 3 items, cursor at 1 (second-to-last): $count-1=2, cursor(1)<2 is true => moves.
     * With mutation $count-2=1, cursor(1)<1 is false => doesn't move. Bug!
     */
    #[Test]
    public function endKeyFromSecondToLastItemMovesToLastItem(): void
    {
        $list = ListView::fromStrings(['A', 'B', 'C']);
        $area = Rect::fromSize(20, 5);
        // Move cursor to 1 (second-to-last)
        $result = $list->update(new KeyMsg(Key::Down), $area);
        self::assertSame(1, $result->model->cursor());
        // Press End
        $result = $result->model->update(new KeyMsg(Key::End), $area);
        self::assertSame(2, $result->model->cursor());
    }

    /**
     * L175: PageDown guard — $this->cursor < $count - 1 vs $count - 2.
     * With 3 items, cursor at 1 (index 1): $count-1=2, cursor(1)<2 is true => PageDown works.
     * With mutation $count-2=1, cursor(1)<1 is false => PageDown does nothing. Bug!
     */
    #[Test]
    public function pageDownFromSecondToLastItemStillMoves(): void
    {
        $list = ListView::fromStrings(['A', 'B', 'C']);
        $area = Rect::fromSize(20, 5);
        $result = $list->update(new KeyMsg(Key::Down), $area);
        self::assertSame(1, $result->model->cursor());
        $result = $result->model->update(new KeyMsg(Key::PageDown), $area);
        self::assertSame(2, $result->model->cursor());
    }

    /**
     * L208: max(0, $maxOffset) vs max(1, $maxOffset).
     * When totalItemRows == area.height, maxOffset = 0.
     * Wheel down should NOT scroll (offset stays 0). With max(1,...), it would set offset=1.
     */
    #[Test]
    public function wheelDownDoesNotScrollWhenItemsFitExactly(): void
    {
        // 3 items in 3-row viewport => maxOffset = 3 - 3 = 0
        $list = ListView::fromStrings(['A', 'B', 'C']);
        $area = new Rect(0, 0, 20, 3);
        $wheelDown = new MouseMsg(5, 0, MouseAction::WheelDown, MouseButton::None);
        $result = $list->update($wheelDown, $area);
        self::assertSame(0, $result->model->scrollOffset());
    }

    /**
     * L208: max(0, $maxOffset) vs max(-1, $maxOffset).
     * When totalItemRows < area.height, maxOffset is negative.
     * max(0, negative) = 0, max(-1, negative) could be -1 if maxOffset <= -1.
     * 2 items in 5-row viewport: maxOffset = 2 - 5 = -3. max(0, -3) = 0, max(-1, -3) = -1.
     */
    #[Test]
    public function wheelDownDoesNotScrollToNegativeOffset(): void
    {
        $list = ListView::fromStrings(['A', 'B']);
        $area = new Rect(0, 0, 20, 5);
        $wheelDown = new MouseMsg(5, 0, MouseAction::WheelDown, MouseButton::None);
        $result = $list->update($wheelDown, $area);
        self::assertSame(0, $result->model->scrollOffset());
    }

    /**
     * A click resolves against the row the item is drawn on.
     *
     * Items are atomic: an offset landing inside one skips past it and the next
     * item is drawn at the top of the viewport. Hit-testing has to measure from
     * there too — measuring from the raw offset puts the click target a row
     * below the glyphs, which is what this pins.
     */
    #[Test]
    public function aClickResolvesAgainstTheRowTheItemIsDrawnOn(): void
    {
        $items = [
            new SimpleListItem('A', 'DescA'), // height=2
            new SimpleListItem('B', 'DescB'), // height=2
            new SimpleListItem('C'),          // height=1
        ];
        $list = ListView::new($items);
        // Small viewport so we can scroll past multiple items
        $smallArea = new Rect(0, 0, 20, 2);

        // Scroll to offset 3 (skip A entirely at height=2, skip first row of B)
        $wheelDown = new MouseMsg(5, 0, MouseAction::WheelDown, MouseButton::None);
        $scrolled = $list;
        for ($i = 0; $i < 3; ++$i) {
            $scrolled = $scrolled->update($wheelDown, $smallArea)->model;
        }
        // maxOffset = 5-2 = 3
        self::assertSame(3, $scrolled->scrollOffset());

        // A and B both start before offset 3, so C is the first item drawn and
        // it sits on the first row of the viewport.
        $frame = new Frame(20, 2);
        $scrolled->render($frame, $smallArea, new RenderContext(Theme::dark()));
        self::assertSame('C', $frame->characters[0]);

        $click = new MouseMsg(5, 0, MouseAction::Press, MouseButton::Left);
        $result = $scrolled->update($click, $smallArea);
        self::assertSame(2, $result->model->cursor());
    }

    /**
     * L264: < vs <= for rowsBefore < offset.
     * When rowsBefore equals offset, the cursor is at the top of the viewport.
     * < means don't scroll up; <= would needlessly reset offset.
     */
    #[Test]
    public function ensureCursorVisibleDoesNotScrollWhenCursorAtTopOfViewport(): void
    {
        $items = array_map(fn (int $index) => "Item $index", range(0, 9));
        $list = ListView::fromStrings($items);
        $area = Rect::fromSize(20, 3);

        // Move to item 5, then scroll should be at 3 (items 3,4,5 visible)
        $current = $list;
        for ($i = 0; $i < 5; ++$i) {
            $current = $current->update(new KeyMsg(Key::Down), $area)->model;
        }
        self::assertSame(5, $current->cursor());
        self::assertSame(3, $current->scrollOffset());

        // Move up to item 3 (top of viewport). scrollOffset should stay at 3.
        $current = $current->update(new KeyMsg(Key::Up), $area)->model;
        $current = $current->update(new KeyMsg(Key::Up), $area)->model;
        self::assertSame(3, $current->cursor());
        self::assertSame(3, $current->scrollOffset()); // rowsBefore(3) == offset(3), should not change
    }

    /**
     * L268: > vs >= for cursorBottom > offset + area.height.
     * When cursorBottom equals offset + height, cursor is exactly at the bottom edge.
     * > means don't scroll; >= would scroll one extra row unnecessarily.
     */
    #[Test]
    public function ensureCursorVisibleDoesNotScrollWhenCursorAtBottomOfViewport(): void
    {
        $items = array_map(fn (int $index) => "Item $index", range(0, 9));
        $list = ListView::fromStrings($items);
        $area = Rect::fromSize(20, 3);

        // Move to item 2 (bottom of initial viewport: items 0,1,2)
        $current = $list;
        $current = $current->update(new KeyMsg(Key::Down), $area)->model;
        $current = $current->update(new KeyMsg(Key::Down), $area)->model;
        self::assertSame(2, $current->cursor());
        // rowsBefore = 2, cursorHeight = 1, cursorBottom = 3
        // offset + height = 0 + 3 = 3
        // cursorBottom(3) > 3 is false => don't scroll. Correct.
        // cursorBottom(3) >= 3 is true => would set offset = 3 - 3 = 0. Same. Hmm.
        // Need a case where it matters: after scrolling.
        // Let's go to item 5 then back to 4, which should be at bottom of viewport.
        $current = $current->update(new KeyMsg(Key::Down), $area)->model; // cursor=3, offset=1
        $current = $current->update(new KeyMsg(Key::Down), $area)->model; // cursor=4, offset=2
        $current = $current->update(new KeyMsg(Key::Down), $area)->model; // cursor=5, offset=3
        self::assertSame(5, $current->cursor());
        self::assertSame(3, $current->scrollOffset());

        // cursorBottom = 5 + 1 = 6, offset + height = 3 + 3 = 6
        // > : 6 > 6 is false => don't scroll. Stays at 3.
        // >= : 6 >= 6 is true => offset = 6 - 3 = 3. Same.
        // The boundary is always the same here because we just scrolled to position the cursor.
        // The mutation is equivalent in all cases I can construct. Let me annotate it.
        self::assertSame(3, $current->scrollOffset());
    }

    #[Test]
    public function renderSkipsItemsBeforeScrollOffset(): void
    {
        $items = array_map(fn (int $index) => "Item $index", range(0, 9));
        $list = ListView::fromStrings($items);
        $area = Rect::fromSize(20, 3);

        $result = $list->update(new KeyMsg(Key::End), $area);
        $model = $result->model;

        $frame = new Frame(20, 3);
        $model->render($frame, $area, new RenderContext(Theme::dark()));

        $row0 = '';
        for ($column = 0; $column < 6; ++$column) {
            $row0 .= $frame->characters[$column];
        }
        self::assertStringContainsString('Item 7', $row0);

        $row2 = '';
        for ($column = 0; $column < 6; ++$column) {
            $row2 .= $frame->characters[2 * 20 + $column];
        }
        self::assertStringContainsString('Item 9', $row2);
    }

    #[Test]
    public function renderTruncatesTitleToAreaWidth(): void
    {
        $list = ListView::fromStrings(['Hello World Long Text']);
        $frame = new Frame(15, 1);
        $list->render($frame, new Rect(0, 0, 10, 1), new RenderContext(Theme::dark()));

        self::assertSame(' ', $frame->characters[10]);
    }

    #[Test]
    public function descriptionNotRenderedWhenWidthEqualsTwoCharOffset(): void
    {
        $items = [new SimpleListItem('T', 'Description')];
        $list = ListView::new($items);
        $frame = new Frame(10, 5);
        $list->render($frame, new Rect(0, 0, 2, 5), new RenderContext(Theme::dark()));

        $secondRow = '';
        for ($column = 0; $column < 10; ++$column) {
            $secondRow .= $frame->characters[1 * 10 + $column];
        }
        self::assertStringNotContainsString('D', $secondRow);
    }

    #[Test]
    public function anAreaTooNarrowForADescriptionStillRendersTheTitle(): void
    {
        // Three columns leave nothing once the scrollbar gutter is reserved, so
        // the item degrades to its title rather than drawing over the bar.
        $list = ListView::new([new SimpleListItem('T', 'Description')]);
        $frame = new Frame(10, 5);

        $list->render($frame, new Rect(0, 0, 3, 5), new RenderContext(Theme::dark()));

        self::assertSame('T', $frame->characters[0]);
    }

    #[Test]
    public function anItemsHeightIsCappedSoOneEntryCannotFillThePane(): void
    {
        $item = new SimpleListItem('title', str_repeat('word ', 60));

        self::assertSame(ListItemLayout::MAX_ROWS, $item->height(12));
    }

    #[Test]
    public function aCappedItemMarksThatItsTextContinues(): void
    {
        $rows = ListItemLayout::rows(new SimpleListItem('title', str_repeat('word ', 60)), 12);

        self::assertCount(ListItemLayout::MAX_ROWS, $rows);
        self::assertStringEndsWith('…', $rows[ListItemLayout::MAX_ROWS - 1]['text']);
    }

    public static function listViewKeybindingOverrideProvider(): iterable
    {
        yield Actions::CURSOR_UP => [Actions::CURSOR_UP, new KeyMsg(Key::Up),       new KeyMsg('a'), 3, fn (ListView $v) => $v->cursor() === 2];
        yield Actions::CURSOR_DOWN => [Actions::CURSOR_DOWN, new KeyMsg(Key::Down),     new KeyMsg('b'), 0, fn (ListView $v) => $v->cursor() === 1];
        yield Actions::HOME => [Actions::HOME,       new KeyMsg(Key::Home),     new KeyMsg('c'), 5, fn (ListView $v) => $v->cursor() === 0];
        yield Actions::END => [Actions::END,        new KeyMsg(Key::End),      new KeyMsg('d'), 0, fn (ListView $v) => $v->cursor() === 9];
        yield Actions::PAGE_UP => [Actions::PAGE_UP,    new KeyMsg(Key::PageUp),   new KeyMsg('e'), 9, fn (ListView $v) => $v->cursor() < 9];
        yield Actions::PAGE_DOWN => [Actions::PAGE_DOWN,  new KeyMsg(Key::PageDown), new KeyMsg('f'), 0, fn (ListView $v) => $v->cursor() > 0];
        yield Actions::SELECT => [Actions::SELECT,     new KeyMsg(Key::Enter),    new KeyMsg('g'), 0, fn (ListView $v) => $v->selectedIndex() === 0];
    }

    #[DataProvider('listViewKeybindingOverrideProvider')]
    #[Test]
    public function supportsCustomKeybinding(
        string $action,
        KeyMsg $defaultKey,
        KeyMsg $customKey,
        int $startingCursor,
        Closure $assertExpected,
    ): void {
        $area = Rect::fromSize(30, 10);
        $items = array_map(fn (int $i) => new SimpleListItem("item $i"), range(1, 10));
        $widget = ListView::new($items)->withCursor($startingCursor);

        $afterDefault = $widget->update($defaultKey, $area)->model;
        self::assertNotSame($widget, $afterDefault, "default key for $action must change state");
        self::assertTrue($assertExpected($afterDefault));

        $customKeyPattern = is_string($customKey->key) ? $customKey->key : 'x';
        $custom = $widget->withKeybindings(new Keybindings([$action => [$customKeyPattern]]));
        self::assertSame($custom, $custom->update($defaultKey, $area)->model);

        $afterCustom = $custom->update($customKey, $area)->model;
        self::assertNotSame($custom, $afterCustom);
        self::assertTrue($assertExpected($afterCustom));
    }

    #[Test]
    public function itNamesTheKeysItAnswers(): void
    {
        self::assertSame(
            ['↑/↓' => 'move', 'Enter' => 'choose'],
            ListView::new([])->hints(),
        );
    }

    #[Test]
    public function homeAtCursorZeroIsANoOpEvenWhenScrolled(): void
    {
        $list = ListView::new($this->tenItems())->withScrollOffset(5);
        $area = new Rect(0, 0, 20, 3);

        $result = $list->update(new KeyMsg(Key::Home), $area)->model;

        self::assertSame(5, $result->scrollOffset(), 'Home with the cursor already at 0 must not touch the wheel-scrolled offset');
    }

    #[Test]
    public function pageUpAtCursorZeroIsANoOpEvenWhenScrolled(): void
    {
        $list = ListView::new($this->tenItems())->withScrollOffset(5);
        $area = new Rect(0, 0, 20, 3);

        $result = $list->update(new KeyMsg(Key::PageUp), $area)->model;

        self::assertSame(5, $result->scrollOffset(), 'PageUp with the cursor already at 0 must not touch the wheel-scrolled offset');
    }

    #[Test]
    public function endAtLastCursorIsANoOpEvenWhenCursorIsOffScreen(): void
    {
        $list = ListView::new($this->tenItems())
                        ->withCursor(9)
            ->withScrollOffset(0);
        $area = new Rect(0, 0, 20, 3);

        $result = $list->update(new KeyMsg(Key::End), $area)->model;

        self::assertSame(0, $result->scrollOffset(), 'End with the cursor already on the last item must not scroll it into view');
    }

    #[Test]
    public function pageDownAtLastCursorIsANoOpEvenWhenCursorIsOffScreen(): void
    {
        $list = ListView::new($this->tenItems())
                        ->withCursor(9)
            ->withScrollOffset(0);
        $area = new Rect(0, 0, 20, 3);

        $result = $list->update(new KeyMsg(Key::PageDown), $area)->model;

        self::assertSame(0, $result->scrollOffset(), 'PageDown with the cursor already on the last item must not scroll it into view');
    }

    #[Test]
    public function renderClipsItemTextToTheArea(): void
    {
        $list = ListView::new([new SimpleListItem('ABCDEFGHIJ')]);
        $frame = new Frame(12, 3);
        $list->render($frame, new Rect(0, 0, 6, 3), new RenderContext(Theme::dark()));

        self::assertSame(' ', $frame->characters[6], 'Item text must not bleed past the right edge of the list area');
    }

    #[Test]
    public function renderClipsATwoRowItemThatStraddlesTheBottomEdge(): void
    {
        $twoRowItems = [
            new SimpleListItem('First', 'first description'),
            new SimpleListItem('Second', 'second description'),
        ];
        $list = ListView::new($twoRowItems);
        $frame = new Frame(24, 5);
        $list->render($frame, new Rect(0, 0, 24, 3), new RenderContext(Theme::dark()));

        self::assertSame(' ', $frame->characters[3 * 24], 'The second item description row falls outside the area and must be clipped');
    }

    #[Test]
    public function renderRestoresTheClipStack(): void
    {
        $list = ListView::new([new SimpleListItem('A')]);
        $frame = new Frame(12, 3);
        $list->render($frame, new Rect(0, 0, 6, 2), new RenderContext(Theme::dark()));

        $frame->putString(8, 2, 'Z');
        self::assertSame('Z', $frame->characters[2 * 12 + 8], 'Writes outside the list area must work after render — the clip must be popped');
    }

    #[Test]
    public function unfocusedListStillShowsWhichRowIsSelected(): void
    {
        // The app may drive the cursor while a sibling widget holds focus;
        // the selection must stay visible or the list looks inert.
        $theme = Theme::dark();
        $list = ListView::new(self::numberedItems(5))->withCursor(2);
        $frame = new Frame(20, 5);

        $list->render($frame, new Rect(0, 0, 20, 5), new RenderContext($theme));

        $cursorRowBackground = $frame->backgrounds[2 * 20];
        self::assertSame($theme->selectedStyle->backgroundValue(), $cursorRowBackground);
        self::assertNotSame($theme->base->backgroundValue(), $cursorRowBackground, 'The selected row must stand out');
    }

    #[Test]
    public function theSelectedRowHighlightSpansTheFullRowWidth(): void
    {
        $theme = Theme::dark();
        $list = ListView::new([new SimpleListItem('AB')])->withCursor(0);
        $frame = new Frame(20, 3);

        $list->render($frame, new Rect(0, 0, 20, 3), RenderContext::focused($theme));

        $expected = $theme->cursorStyle->backgroundValue();
        self::assertSame($expected, $frame->backgrounds[0], 'Highlight starts at the row start');
        self::assertSame($expected, $frame->backgrounds[19], 'Highlight reaches the row end, not just the text');
    }

    #[Test]
    public function theHighlightCoversEveryRowOfATwoRowItem(): void
    {
        $theme = Theme::dark();
        $list = ListView::new([new SimpleListItem('Title', 'Description')])->withCursor(0);
        $frame = new Frame(20, 4);

        $list->render($frame, new Rect(0, 0, 20, 4), new RenderContext($theme));

        $expected = $theme->selectedStyle->backgroundValue();
        self::assertSame($expected, $frame->backgrounds[19], 'Title row filled to the edge');
        self::assertSame($expected, $frame->backgrounds[1 * 20 + 19], 'Description row filled to the edge too');
    }

    #[Test]
    public function rowsOtherThanTheSelectedOneKeepThePlainBackground(): void
    {
        $theme = Theme::dark();
        $list = ListView::new(self::numberedItems(5))->withCursor(0);
        $frame = new Frame(20, 5);

        $list->render($frame, new Rect(0, 0, 20, 5), new RenderContext($theme));

        self::assertNotSame($theme->selectedStyle->backgroundValue(), $frame->backgrounds[1 * 20]);
    }

    #[Test]
    public function unfocusedSelectionUsesReadableTextOnTheHighlight(): void
    {
        $theme = Theme::dark();
        $list = ListView::new(self::numberedItems(3))->withCursor(1);
        $frame = new Frame(20, 3);

        $list->render($frame, new Rect(0, 0, 20, 3), new RenderContext($theme));

        self::assertNotSame($theme->dim->foregroundValue(), $frame->foregrounds[1 * 20], 'Dim text on the highlight is unreadable');
    }

    #[Test]
    public function theSelectedTitleIsAccentedSoItDoesNotBlendIntoTheList(): void
    {
        $theme = Theme::dark();
        $list = ListView::new(self::numberedItems(3))->withCursor(1);
        $frame = new Frame(20, 3);

        $list->render($frame, new Rect(0, 0, 20, 3), new RenderContext($theme));

        self::assertSame($theme->accent->foregroundValue(), $frame->foregrounds[1 * 20]);
        self::assertSame(Attr::BOLD, $frame->attributes[1 * 20] & Attr::BOLD);
    }

    #[Test]
    public function theSelectedDescriptionStaysReadable(): void
    {
        // Regression: a dim description on the highlight was invisible.
        $theme = Theme::dark();
        $list = ListView::new([new SimpleListItem('Title', 'Description')])->withCursor(0);
        $frame = new Frame(20, 4);

        $list->render($frame, new Rect(0, 0, 20, 4), new RenderContext($theme));

        $descriptionForeground = $frame->foregrounds[1 * 20 + 2];
        self::assertNotSame($theme->dim->foregroundValue(), $descriptionForeground);
        self::assertSame($theme->accent->foregroundValue(), $descriptionForeground, 'The description takes the row colour');
    }

    #[Test]
    public function theFocusedSelectionDescriptionUsesTheCursorColours(): void
    {
        // A focused cursor row has a light background with dark text; drawing
        // the description in the light body colour makes it invisible.
        $theme = Theme::dark();
        $list = ListView::new([new SimpleListItem('Title', 'Description')])
            ->withCursor(0)
        ;
        $frame = new Frame(20, 4);

        $list->render($frame, new Rect(0, 0, 20, 4), RenderContext::focused($theme));

        $descriptionIndex = 1 * 20 + 2;
        self::assertSame($theme->cursorStyle->foregroundValue(), $frame->foregrounds[$descriptionIndex]);
        self::assertSame($theme->cursorStyle->backgroundValue(), $frame->backgrounds[$descriptionIndex]);
        self::assertNotSame($theme->base->foregroundValue(), $frame->foregrounds[$descriptionIndex], 'Body text on the cursor background is unreadable');
    }

    #[Test]
    public function theSelectedTitleStaysBolderThanItsDescription(): void
    {
        $list = ListView::new([new SimpleListItem('Title', 'Description')])
            ->withCursor(0)
        ;
        $frame = new Frame(20, 4);

        $list->render($frame, new Rect(0, 0, 20, 4), new RenderContext(Theme::dark()));

        self::assertSame(0, $frame->attributes[1 * 20 + 2] & Attr::BOLD, 'Only the title carries emphasis');
    }

    #[Test]
    public function withCursorKeepsTheCursorVisibleAtRenderTime(): void
    {
        $list = ListView::new(self::numberedItems(50))->withCursor(20);
        $frame = new Frame(20, 5);

        $list->render($frame, new Rect(0, 0, 20, 5), new RenderContext(Theme::dark()));

        self::assertSame('Item-20', self::renderedRow($frame, 4, 10), 'An externally moved cursor must scroll into view');
        self::assertSame('Item-16', self::renderedRow($frame, 0, 10));
    }

    #[Test]
    public function wheelScrollingAwayStopsFollowingTheCursor(): void
    {
        $list = ListView::new(self::numberedItems(50));
        $area = new Rect(0, 0, 20, 5);

        for ($turn = 0; $turn < 3; ++$turn) {
            $list = $list->update(new MouseMsg(1, 1, MouseAction::WheelDown, MouseButton::None), $area)->model;
        }

        $frame = new Frame(20, 5);
        $list->render($frame, $area, new RenderContext(Theme::dark()));

        self::assertSame('Item-3', self::renderedRow($frame, 0, 10), 'Explicit scrolling must not snap back to the cursor');
    }

    #[Test]
    public function movingTheCursorAfterWheelScrollingResumesFollowing(): void
    {
        $list = ListView::new(self::numberedItems(50));
        $area = new Rect(0, 0, 20, 5);

        $list = $list->update(new MouseMsg(1, 1, MouseAction::WheelDown, MouseButton::None), $area)->model;
        $list = $list->withCursor(30);

        $frame = new Frame(20, 5);
        $list->render($frame, $area, new RenderContext(Theme::dark()));

        self::assertSame('Item-30', self::renderedRow($frame, 4, 10), 'Cursor movement re-arms follow mode');
    }

    #[Test]
    public function withScrollOffsetStopsFollowingTheCursor(): void
    {
        $list = ListView::new(self::numberedItems(50))->withCursor(20)->withScrollOffset(0);
        $frame = new Frame(20, 5);

        $list->render($frame, new Rect(0, 0, 20, 5), new RenderContext(Theme::dark()));

        self::assertSame('Item-0', self::renderedRow($frame, 0, 10), 'An explicit scroll offset wins over cursor following');
    }

    #[Test]
    public function aCursorSetBeforeTheFirstFrameIsFollowed(): void
    {
        $list = ListView::new(self::numberedItems(50))->withCursor(20);
        $frame = new Frame(20, 5);

        $list->render($frame, new Rect(0, 0, 20, 5), new RenderContext(Theme::dark()));

        self::assertSame('Item-20', self::renderedRow($frame, 4, 10), 'followCursor must default to true');
    }

    #[Test]
    public function negativeCursorRendersTheFirstItemsWithoutErrors(): void
    {
        $list = ListView::new(self::numberedItems(10))->withCursor(-5);
        $frame = new Frame(20, 5);

        $list->render($frame, new Rect(0, 0, 20, 5), new RenderContext(Theme::dark()));

        self::assertSame('Item-0', self::renderedRow($frame, 0, 10), 'A negative cursor clamps to the first item');
    }

    #[Test]
    public function mouseClickReArmsCursorFollowing(): void
    {
        $list = ListView::new(self::numberedItems(50))->withCursor(20);
        $area = new Rect(0, 0, 20, 5);

        // Following displays items 16..20 (stored offset is still 0). Clicking the
        // first visible row selects item 16 and must keep it visible afterwards.
        $clicked = $list->update(new MouseMsg(1, 0, MouseAction::Press, MouseButton::Left), $area)->model;

        $frame = new Frame(20, 5);
        $clicked->render($frame, $area, new RenderContext(Theme::dark()));

        self::assertSame('Item-12', self::renderedRow($frame, 0, 10), 'After the click, the view follows the clicked cursor (offset 12..16), not the stale stored offset 0');
        self::assertSame('Item-16', self::renderedRow($frame, 4, 10));
    }

    #[Test]
    public function wheelUpAlsoStopsFollowingTheCursor(): void
    {
        $list = ListView::new(self::numberedItems(50));
        $area = new Rect(0, 0, 20, 5);

        for ($turn = 0; $turn < 3; ++$turn) {
            $list = $list->update(new MouseMsg(1, 1, MouseAction::WheelDown, MouseButton::None), $area)->model;
        }
        $list = $list->update(new MouseMsg(1, 1, MouseAction::WheelUp, MouseButton::None), $area)->model;

        $frame = new Frame(20, 5);
        $list->render($frame, $area, new RenderContext(Theme::dark()));

        self::assertSame('Item-2', self::renderedRow($frame, 0, 10), 'Wheel-up scrolling must not snap back to the cursor at row 0');
    }

    #[Test]
    public function outOfRangeCursorRendersTheLastItemsWithoutErrors(): void
    {
        $list = ListView::new(self::numberedItems(10))->withCursor(999);
        $frame = new Frame(20, 5);

        $list->render($frame, new Rect(0, 0, 20, 5), new RenderContext(Theme::dark()));

        self::assertSame('Item-9', self::renderedRow($frame, 4, 10), 'An out-of-range cursor clamps to the last item');
    }

    #[Test]
    public function mouseClickHitsTheDisplayedItemWhileFollowingTheCursor(): void
    {
        $list = ListView::new(self::numberedItems(50))->withCursor(20);
        $area = new Rect(0, 0, 20, 5);

        // Following the cursor displays items 16..20; a click on the first row must select item 16.
        $result = $list->update(new MouseMsg(1, 0, MouseAction::Press, MouseButton::Left), $area);

        self::assertInstanceOf(ListView::class, $result->model);
        self::assertSame(16, $result->model->cursor(), 'Click mapping must use the displayed scroll offset, not the stale stored one');
    }

    #[Test]
    public function itemTextDoesNotRunIntoTheScrollbar(): void
    {
        // A scrollbar overlapping the text truncates it without a hint and the
        // two glyphs collide.
        $items = [];
        for ($index = 0; $index < 20; ++$index) {
            $items[] = new SimpleListItem(str_repeat('X', 40));
        }
        $list = ListView::new($items);
        $frame = new Frame(20, 6);

        $list->render($frame, new Rect(0, 0, 20, 6), new RenderContext(Theme::dark()));

        self::assertSame(Scrollbar::THUMB, $frame->characters[19], 'The scrollbar owns the last column');
        self::assertSame(' ', $frame->characters[18], 'A gap separates the text from the scrollbar');
        self::assertSame('X', $frame->characters[17]);
    }

    #[Test]
    public function fullWidthIsUsedWhenNoScrollbarIsNeeded(): void
    {
        $list = ListView::new([new SimpleListItem(str_repeat('X', 40))]);
        $frame = new Frame(20, 6);

        $list->render($frame, new Rect(0, 0, 20, 6), new RenderContext(Theme::dark()));

        self::assertSame('X', $frame->characters[19], 'Without a scrollbar the text may fill the row');
    }

    #[Test]
    public function longItemTextWrapsInsteadOfBeingCutOff(): void
    {
        $list = ListView::new([new SimpleListItem('cache:flushtags', 'Flush saved caches with tags')]);
        $frame = new Frame(20, 6);

        $list->render($frame, new Rect(0, 0, 20, 6), new RenderContext(Theme::dark()));

        self::assertSame('cache:flushtags', self::row($frame, 0, 20));
        self::assertSame('Flush saved caches', self::row($frame, 1, 20));
        self::assertSame('with tags', self::row($frame, 2, 20), 'The tail of the description keeps its own row');
    }

    #[Test]
    public function aWrappedItemReportsTheRowsItOccupies(): void
    {
        $item = new SimpleListItem('cache:flushtags', 'Flush saved caches with tags');

        self::assertSame(2, $item->height(60), 'Title and description fit one row each');
        self::assertSame(3, $item->height(20), 'The description needs a second row when narrow');
    }

    #[Test]
    public function wrappedRowsShiftTheItemsAfterThem(): void
    {
        $list = ListView::new([
            new SimpleListItem('first', 'a description long enough to wrap here'),
            new SimpleListItem('second'),
        ]);
        $frame = new Frame(20, 8);

        $list->render($frame, new Rect(0, 0, 20, 8), new RenderContext(Theme::dark()));

        // 'second' must appear below every row the first item grew into.
        $rows = [];
        for ($row = 0; $row < 8; ++$row) {
            $rows[] = self::row($frame, $row, 20);
        }

        self::assertSame(4, array_search('second', $rows, true), 'The next item starts after the wrapped rows');
    }

    #[Test]
    public function clickingAWrappedItemSelectsIt(): void
    {
        $list = ListView::new([
            new SimpleListItem('first', 'a description long enough to wrap here'),
            new SimpleListItem('second'),
        ]);
        $area = new Rect(0, 0, 20, 8);

        // 'second' sits on row 4, below the rows the first item wrapped into.
        $result = $list->update(new MouseMsg(2, 4, MouseAction::Press, MouseButton::Left), $area);

        self::assertInstanceOf(ListView::class, $result->model);
        self::assertSame(1, $result->model->cursor());
    }

    #[Test]
    public function stopsMeasuringOnceItIsCertainTheContentOverflows(): void
    {
        $counter = new MeasurementCounter();
        $items = [];
        for ($index = 0; $index < 200; ++$index) {
            $items[] = new CountingListItem("Item-$index", $counter);
        }

        ListView::new($items)->render(new Frame(20, 10), new Rect(0, 0, 20, 10), new RenderContext(Theme::dark()));

        self::assertLessThan(
            count($items) * 2,
            $counter->count,
            'Deciding on a scrollbar must not measure the whole collection a second time',
        );
    }

    #[Test]
    public function aPlainStringIsRefusedWhereItIsHandedIn(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ListView takes ListItem instances; item 0 is string');

        ListView::new(self::itemsFromUntypedData());
    }

    /**
     * What a caller reading rows out of JSON or a config file hands in: the
     * signature says ListItem, the value never was one.
     *
     * @return list<ListItem>
     */
    private static function itemsFromUntypedData(): array
    {
        /** @var list<ListItem> $items */
        $items = ['plain'];

        return $items;
    }

    /**
     * @return list<string>
     */
    private function titlesOnScreen(ListView $list, Rect $area): array
    {
        $frame = new Frame($area->width, $area->height);
        $list->render($frame, $area, new RenderContext(Theme::dark()));
        $screen = ScreenBuffer::fromFrame($frame)->plainText();

        $titles = [];
        for ($index = 0; $index < 20; ++$index) {
            if (preg_match('/\bItem ' . $index . '\b/', $screen) === 1) {
                $titles[] = 'Item ' . $index;
            }
        }

        return $titles;
    }

    /**
     * @return list<SimpleListItem>
     */
    private function tenItems(): array
    {
        return array_map(fn (int $index) => new SimpleListItem("Item $index"), range(0, 9));
    }

    /**
     * @return list<SimpleListItem>
     */
    private static function numberedItems(int $count): array
    {
        $items = [];
        for ($index = 0; $index < $count; ++$index) {
            $items[] = new SimpleListItem("Item-$index");
        }

        return $items;
    }

    private static function renderedRow(Frame $frame, int $row, int $width): string
    {
        $text = '';
        for ($column = 0; $column < $width; ++$column) {
            $text .= $frame->characters[$row * $frame->width + $column];
        }

        return rtrim($text);
    }

    private static function row(Frame $frame, int $row, int $width): string
    {
        $text = '';
        for ($column = 0; $column < $width; ++$column) {
            $text .= $frame->characters[$row * $width + $column];
        }

        return trim($text);
    }
}

final class MeasurementCounter
{
    public int $count = 0;
}

final readonly class CountingListItem implements ListItem
{
    public function __construct(private string $title, private MeasurementCounter $counter)
    {
    }

    public function title(): string
    {
        return $this->title;
    }

    public function description(): string
    {
        return '';
    }

    public function filterValue(): string
    {
        return $this->title;
    }

    public function height(int $width): int
    {
        ++$this->counter->count;

        return ListItemLayout::height($this, $width);
    }
}
