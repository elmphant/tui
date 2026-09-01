<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Closure;
use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseButton;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Rendering\Attr;
use Elmphant\Tui\Rendering\Color;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Span;
use Elmphant\Tui\Rendering\Style;
use Elmphant\Tui\Rendering\StyleToken;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Tests\Support\TestMessage;
use Elmphant\Tui\Widget\Bar\Scrollbar;
use Elmphant\Tui\Widget\Focusable;
use Elmphant\Tui\Widget\Table;
use Elmphant\Tui\Widget\Table\Column;
use Elmphant\Tui\Widget\Table\Row;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TableTest extends TestCase
{
    use FocusableBehaviorTrait;

    #[Test]
    public function itNamesTheKeysItAnswers(): void
    {
        self::assertSame(
            ['↑/↓' => 'move', 'Enter' => 'choose'],
            Table\Table::fromCells([], [])->hints(),
        );
    }

    #[Test]
    public function implementsFocusable(): void
    {
        self::assertInstanceOf(Focusable::class, $this->createTable());
    }

    #[Test]
    public function constraintReturnsFlex(): void
    {
        $table = $this->createTable();
        self::assertSame(1, $table->sizing()->rows->flex);
    }

    #[Test]
    public function cursorStartsAtZero(): void
    {
        self::assertSame(0, $this->createTable()->cursor());
    }

    #[Test]
    public function arrowDownMovesCursor(): void
    {
        $table = $this->createTable()->withFocus(true);
        $area = Rect::fromSize(60, 10);
        $result = $table->update(new KeyMsg(Key::Down), $area);
        self::assertSame(1, $result->model->cursor());
    }

    #[Test]
    public function arrowDownDoesNotMovePastLast(): void
    {
        $table = $this->createTable()->withFocus(true);
        $area = Rect::fromSize(60, 10);
        $result = $table->update(new KeyMsg(Key::Down), $area);
        $result = $result->model->update(new KeyMsg(Key::Down), $area);
        $result = $result->model->update(new KeyMsg(Key::Down), $area);
        self::assertSame(2, $result->model->cursor());
    }

    #[Test]
    public function arrowUpMovesCursor(): void
    {
        $table = $this->createTable()->withFocus(true);
        $area = Rect::fromSize(60, 10);
        $result = $table->update(new KeyMsg(Key::Down), $area);
        $result = $result->model->update(new KeyMsg(Key::Up), $area);
        self::assertSame(0, $result->model->cursor());
    }

    #[Test]
    public function arrowUpAtTopReturnsSelf(): void
    {
        $table = $this->createTable()->withFocus(true);
        $area = Rect::fromSize(60, 10);
        $result = $table->update(new KeyMsg(Key::Up), $area);
        self::assertSame($table, $result->model);
    }

    #[Test]
    public function enterSelectsRow(): void
    {
        $table = $this->createTable()->withFocus(true);
        $area = Rect::fromSize(60, 10);
        $result = $table->update(new KeyMsg(Key::Down), $area);
        $result = $result->model->update(new KeyMsg(Key::Enter), $area);
        self::assertSame(1, $result->model->selectedIndex());
    }

    #[Test]
    public function selectedRow(): void
    {
        $table = $this->createTable()->withFocus(true);
        $area = Rect::fromSize(60, 10);
        $result = $table->update(new KeyMsg(Key::Down), $area);
        $result = $result->model->update(new KeyMsg(Key::Enter), $area);
        self::assertSame(['2', 'Bob', 'Inactive'], $result->model->selectedRow());
    }

    #[Test]
    public function selectedRowWithNoSelection(): void
    {
        self::assertNull($this->createTable()->selectedRow());
    }

    #[Test]
    public function homeMovesCursorToFirst(): void
    {
        $table = $this->createTable()->withFocus(true);
        $area = Rect::fromSize(60, 10);
        $result = $table->update(new KeyMsg(Key::End), $area);
        $result = $result->model->update(new KeyMsg(Key::Home), $area);
        self::assertSame(0, $result->model->cursor());
    }

    #[Test]
    public function endMovesCursorToLast(): void
    {
        $table = $this->createTable()->withFocus(true);
        $area = Rect::fromSize(60, 10);
        $result = $table->update(new KeyMsg(Key::End), $area);
        self::assertSame(2, $result->model->cursor());
    }

    #[Test]
    public function pageDownAndUp(): void
    {
        $rows = array_map(fn (int $index) => [(string)$index, "Name $index", 'Active'], range(1, 20));
        $table = Table\Table::fromCells(
            [Column::new('ID', Constraint::exact(5)), Column::new('Name', Constraint::flex(1)), Column::new('Status', Constraint::flex(1))],
            $rows,
        )->withFocus(true);
        $area = Rect::fromSize(60, 8);

        $result = $table->update(new KeyMsg(Key::PageDown), $area);
        self::assertGreaterThan(0, $result->model->cursor());

        $result = $result->model->update(new KeyMsg(Key::PageUp), $area);
        self::assertLessThan(20, $result->model->cursor());
    }

    #[Test]
    public function ignoresInputWhenNotFocused(): void
    {
        $table = $this->createTable();
        $area = Rect::fromSize(60, 10);
        $result = $table->update(new KeyMsg(Key::Down), $area);
        self::assertSame($table, $result->model);
    }

    #[Test]
    public function mouseClickSelectsRow(): void
    {
        $table = $this->createTable();
        $area = new Rect(0, 0, 60, 10);

        // Click on row 2 (y=2, accounting for header at y=0)
        $mouseMessage = new MouseMsg(5, 2, MouseAction::Press, MouseButton::Left);
        $result = $table->update($mouseMessage, $area);
        self::assertSame(1, $result->model->cursor());
        self::assertSame(1, $result->model->selectedIndex());
    }

    #[Test]
    public function mouseClickOnHeaderDoesNothing(): void
    {
        $table = $this->createTable();
        $area = new Rect(0, 0, 60, 10);

        $mouseMessage = new MouseMsg(5, 0, MouseAction::Press, MouseButton::Left);
        $result = $table->update($mouseMessage, $area);
        self::assertSame($table, $result->model);
    }

    #[Test]
    public function mouseClickOutsideDoesNothing(): void
    {
        $table = $this->createTable();
        $area = new Rect(0, 0, 60, 10);

        $mouseMessage = new MouseMsg(5, 20, MouseAction::Press, MouseButton::Left);
        $result = $table->update($mouseMessage, $area);
        self::assertSame($table, $result->model);
    }

    #[Test]
    public function mouseWheelScrolls(): void
    {
        $rows = array_map(fn (int $index) => [(string)$index, "Name $index"], range(1, 30));
        $table = Table\Table::fromCells(
            [Column::new('ID', Constraint::exact(5)), Column::new('Name', Constraint::flex(1))],
            $rows,
        );
        $area = new Rect(0, 0, 40, 5);

        $wheelDown = new MouseMsg(5, 2, MouseAction::WheelDown, MouseButton::None);
        $result = $table->update($wheelDown, $area);
        self::assertGreaterThan(0, $result->model->scrollOffset());
    }

    #[Test]
    public function rendersHeaderWithBoldAttribute(): void
    {
        $table = $this->createTable()->withFocus(true);
        $frame = new Frame(60, 10);
        $area = new Rect(0, 0, 60, 10);
        $table->render($frame, $area, new RenderContext(Theme::dark()));

        self::assertSame('I', $frame->characters[0]);
        self::assertSame('D', $frame->characters[1]);
        self::assertSame(Attr::BOLD | Attr::UNDERLINE, $frame->attributes[0]);
    }

    #[Test]
    public function rendersDataRows(): void
    {
        $table = $this->createTable()->withFocus(true);
        $frame = new Frame(60, 10);
        $area = new Rect(0, 0, 60, 10);
        $table->render($frame, $area, new RenderContext(Theme::dark()));

        // Row 1 data starts at y=1
        $firstDataRow = '';
        for ($column = 0; $column < 5; ++$column) {
            $firstDataRow .= $frame->characters[60 + $column];
        }
        self::assertStringContainsString('1', $firstDataRow);
    }

    #[Test]
    public function rendersHighlightSymbolForCursorRow(): void
    {
        $table = $this->createTable()->withFocus(true);
        $frame = new Frame(60, 10);
        $area = new Rect(0, 0, 60, 10);
        $table->render($frame, $area, new RenderContext(Theme::dark()));

        // Check cursor row has highlighted colors (accent color)
        $firstDataCellForeground = $frame->foregrounds[60]; // y=1, x=0
        self::assertNotSame(0, $firstDataCellForeground);
    }

    #[Test]
    public function emptyTableRenderDoesNotCrash(): void
    {
        $table = Table\Table::fromCells([Column::new('ID', Constraint::exact(5))], []);
        $frame = new Frame(20, 5);
        $table->render($frame, Rect::fromSize(20, 5), new RenderContext(Theme::dark()));
        self::assertTrue(true);
    }

    #[Test]
    public function emptyTableHandlesKeysGracefully(): void
    {
        $table = Table\Table::fromCells([Column::new('ID', Constraint::exact(5))], [])->withFocus(true);
        $area = Rect::fromSize(20, 5);
        $result = $table->update(new KeyMsg(Key::Down), $area);
        self::assertSame($table, $result->model);
    }

    #[Test]
    public function renderWithZeroWidthDoesNotCrash(): void
    {
        $table = $this->createTable();
        $frame = new Frame(20, 5);
        $table->render($frame, new Rect(0, 0, 0, 5), new RenderContext(Theme::dark()));
        self::assertSame(' ', $frame->characters[0]);
    }

    #[Test]
    public function rendersScrollbarWhenRowsOverflow(): void
    {
        $rows = [];
        for ($index = 0; $index < 20; ++$index) {
            $rows[] = [(string)$index, "Task $index"];
        }
        $table = Table\Table::fromCells(
            [Column::new('ID', Constraint::exact(4)), Column::new('Task', Constraint::flex())],
            $rows,
        )->withFocus(true);

        $frame = new Frame(30, 5);
        $area = new Rect(0, 0, 30, 5);
        $table->render($frame, $area, new RenderContext(Theme::dark()));

        $scrollbarCol = 29;
        $hasThumb = false;
        for ($row = 1; $row < 5; ++$row) {
            if ($frame->characters[$row * 30 + $scrollbarCol] === Scrollbar::THUMB) {
                $hasThumb = true;
                break;
            }
        }
        self::assertTrue($hasThumb, 'Should have scrollbar thumb');
    }

    #[Test]
    public function rowsThatWrapPastTheAreaGetAScrollbarThoughFewerRowsThanLinesFit(): void
    {
        $long = str_repeat('word ', 12);
        $table = Table\Table::fromCells(
            [Column::new('ID', Constraint::exact(4)), Column::new('Task', Constraint::flex())],
            [['1', $long], ['2', $long], ['3', $long]],
        )->withFocus(true);

        $frame = new Frame(30, 6);
        $table->render($frame, new Rect(0, 0, 30, 6), new RenderContext(Theme::dark()));

        $column = 29;
        $glyphs = [];
        for ($row = 1; $row < 6; ++$row) {
            $glyphs[] = $frame->characters[$row * 30 + $column];
        }

        self::assertContains(
            Scrollbar::THUMB,
            $glyphs,
            'three rows fit but their wrapped lines do not, so the rows the reader cannot see need a scrollbar',
        );
    }

    #[Test]
    public function theThumbReachesTheBottomOnceTheLastWrappedLinesAreInView(): void
    {
        $long = str_repeat('word ', 12);
        $table = Table\Table::fromCells(
            [Column::new('ID', Constraint::exact(4)), Column::new('Task', Constraint::flex())],
            [['1', $long], ['2', $long], ['3', $long]],
        )->withFocus(true)->withScrollOffset(2);

        $frame = new Frame(30, 6);
        $table->render($frame, new Rect(0, 0, 30, 6), new RenderContext(Theme::dark()));

        self::assertSame(
            Scrollbar::THUMB,
            $frame->characters[5 * 30 + 29],
            'the reader is on the last row, so the thumb has to be at the end of the track',
        );
    }

    #[Test]
    public function noScrollbarWhenAllRowsFit(): void
    {
        $table = Table\Table::fromCells(
            [Column::new('ID', Constraint::exact(4))],
            [['1'], ['2']],
        );
        $frame = new Frame(30, 10);
        $area = new Rect(0, 0, 30, 10);
        $table->render($frame, $area, new RenderContext(Theme::dark()));

        self::assertSame(' ', $frame->characters[29]);
    }

    #[Test]
    public function tableAcceptsRows(): void
    {
        $table = Table\Table::new(
            [Column::new('Name', Constraint::flex())],
            [Row::new('Alice'), Row::new('Bob')],
        );
        self::assertSame(0, $table->cursor());
    }

    #[Test]
    public function cursorSkipsNonSelectableRows(): void
    {
        $table = Table\Table::new(
            [Column::new('Name', Constraint::flex())],
            [Row::header('Group'), Row::new('Alice'), Row::new('Bob')],
        )->withFocus(true);

        // Cursor starts at 1 (first selectable, skipping header at 0)
        self::assertSame(1, $table->cursor());

        // Down moves to 2 (Bob), skipping no headers in between
        $result = $table->update(new KeyMsg(Key::Down), new Rect(0, 0, 40, 10));
        self::assertSame(2, $result->model->cursor());

        // Up from Alice should stay at 1 (header at 0 is skipped)
        $result = $table->update(new KeyMsg(Key::Up), new Rect(0, 0, 40, 10));
        self::assertSame(1, $result->model->cursor());
    }

    #[Test]
    public function aScrollbarTakesAColumnFromTheTableOnlyWhenTheRowsDoNotFit(): void
    {
        $columns = [Column::new('A', Constraint::flex())];
        $cell = 'abcdefghij';

        // A three-row area leaves two lines for rows once the header takes one.
        $fits = $this->firstRowLine(Table\Table::fromCells($columns, [[$cell], [$cell]]), 10, 3);
        $overflows = $this->firstRowLine(Table\Table::fromCells($columns, [[$cell], [$cell], [$cell]]), 10, 3);

        self::assertSame($cell, $fits, 'Two rows fit, so no column is reserved');
        self::assertSame('abcdefghi▐', $overflows, 'A third row needs a scrollbar, leaving nine columns for the cell');
    }

    #[Test]
    public function aCellTooWideForItsColumnIsTruncatedNotSpilledIntoTheNext(): void
    {
        $frame = new Frame(20, 3);
        $table = Table\Table::fromCells(
            [Column::new('A', Constraint::exact(6)), Column::new('B', Constraint::exact(6))],
            [['aaaaaaaaaa', 'bb']],
        );

        $table->render($frame, new Rect(0, 0, 20, 3), new RenderContext(Theme::dark()));

        $row = '';
        for ($column = 0; $column < 12; ++$column) {
            $row .= $frame->characters[20 + $column];
        }

        self::assertSame('aaaaaa', substr($row, 0, 6), 'Clipped to its own six columns');
        self::assertSame('bb', substr($row, 6, 2), 'The next column starts on time');
    }

    #[Test]
    public function aColumnWithNoWidthIsSkippedWithoutShiftingTheRest(): void
    {
        $frame = new Frame(20, 3);
        $table = Table\Table::fromCells(
            [
                Column::new('A', Constraint::exact(4)),
                Column::new('Z', Constraint::exact(0)),
                Column::new('B', Constraint::exact(4)),
            ],
            [['aa', 'zz', 'bb']],
        );

        $table->render($frame, new Rect(0, 0, 20, 3), new RenderContext(Theme::dark()));

        $header = '';
        for ($column = 0; $column < 12; ++$column) {
            $header .= $frame->characters[$column];
        }

        self::assertSame('A', $header[0]);
        self::assertStringNotContainsString('Z', $header, 'A zero-width column draws nothing');
    }

    #[Test]
    public function aColumnFloorIsReservedBeforeTheFlexColumnsShare(): void
    {
        $frame = new Frame(21, 3);
        $table = Table\Table::fromCells(
            [
                Column::new('A', Constraint::min(10)),
                Column::new('B', Constraint::flex(1)),
                Column::new('C', Constraint::flex(1)),
            ],
            [['one', 'two', 'three']],
        );

        $table->render($frame, new Rect(0, 0, 21, 3), new RenderContext(Theme::dark()));

        $header = '';
        for ($column = 0; $column < 21; ++$column) {
            $header .= $frame->characters[$column];
        }

        self::assertSame('A', $header[0]);
        self::assertSame('B', $header[10], 'The floor is reserved before anything is shared');
        self::assertSame('C', $header[15], 'The eleven cells left over split five and six');
    }

    #[Test]
    public function flexColumnsShareWhatTheFixedColumnsLeaveAndTheLastTakesTheRemainder(): void
    {
        $frame = new Frame(41, 3);
        $table = Table\Table::fromCells(
            [
                Column::new('A', Constraint::exact(10)),
                Column::new('B', Constraint::flex(1)),
                Column::new('C', Constraint::flex(2)),
            ],
            [['one', 'two', 'three']],
        );

        $table->render($frame, new Rect(0, 0, 41, 3), new RenderContext(Theme::dark()));

        $header = '';
        for ($column = 0; $column < 41; ++$column) {
            $header .= $frame->characters[$column];
        }

        // 41 columns less the fixed 10 leaves 31 for flex weights 1 and 2:
        // floor(31/3)=10 and floor(62/3)=20, and the last flex column takes the
        // leftover column so the three widths account for all 41.
        self::assertSame('A', $header[0]);
        self::assertSame('B', $header[10]);
        self::assertSame('C', $header[20]);
    }

    #[Test]
    public function homeAndEndReachTheFirstAndLastSelectableRows(): void
    {
        $area = new Rect(0, 0, 40, 10);
        $table = Table\Table::new(
            [Column::new('Name', Constraint::flex())],
            [Row::new('Alice'), Row::new('Bob'), Row::new('Carol')],
        )->withFocus(true)->withCursor(1);

        self::assertSame(0, $table->update(new KeyMsg(Key::Home), $area)->model->cursor());
        self::assertSame(2, $table->update(new KeyMsg(Key::End), $area)->model->cursor());
    }

    #[Test]
    public function homeAndEndStopAtSelectableRowsWhenTheEdgesAreHeaders(): void
    {
        $area = new Rect(0, 0, 40, 10);
        $table = Table\Table::new(
            [Column::new('Name', Constraint::flex())],
            [Row::header('Top'), Row::new('Alice'), Row::new('Bob'), Row::header('Bottom')],
        )->withFocus(true)->withCursor(2);

        self::assertSame(1, $table->update(new KeyMsg(Key::Home), $area)->model->cursor(), 'Past the leading header');
        self::assertSame(2, $table->update(new KeyMsg(Key::End), $area)->model->cursor(), 'Back off the trailing header');
    }

    #[Test]
    public function homeAndEndOnPlainRowsReachBothEnds(): void
    {
        $area = new Rect(0, 0, 40, 10);
        $table = Table\Table::fromCells(
            [Column::new('Name', Constraint::flex())],
            [['Alice'], ['Bob'], ['Carol'], ['Dave']],
        )->withFocus(true)->withCursor(2);

        self::assertSame(0, $table->update(new KeyMsg(Key::Home), $area)->model->cursor());
        self::assertSame(3, $table->update(new KeyMsg(Key::End), $area)->model->cursor());
    }

    #[Test]
    public function headerRowRendersFullWidth(): void
    {
        $frame = new Frame(40, 5);
        $context = new RenderContext(Theme::dark());
        $table = Table\Table::new(
            [Column::new('Name', Constraint::flex()), Column::new('Age', Constraint::exact(5))],
            [Row::header('People'), Row::new('Alice', '30')],
        )->withFocus(true);

        $table->render($frame, new Rect(0, 0, 40, 5), $context);

        // Header row at y=1 should have 'P' at x=0
        self::assertSame('P', $frame->characters[1 * 40 + 0]);
    }

    #[Test]
    public function rowStyleOverridesDefault(): void
    {
        $redStyle = new Style(foreground: Color::ansi(1));
        $frame = new Frame(40, 5);
        $context = new RenderContext(Theme::dark());
        $table = Table\Table::new(
            [Column::new('Name', Constraint::flex())],
            [Row::new('Alice')->withStyle($redStyle)],
        );
        $table->render($frame, new Rect(0, 0, 40, 5), $context);

        self::assertSame($redStyle->foregroundValue(), $frame->foregrounds[1 * 40 + 0]);
    }

    #[Test]
    public function spanCellUsesSpanStyle(): void
    {
        $greenStyle = new Style(foreground: Color::ansi(2));
        $frame = new Frame(40, 5);
        $context = new RenderContext(Theme::dark());
        $table = Table\Table::new(
            [Column::new('Name', Constraint::flex())],
            [Row::new(Span::styled('Alice', $greenStyle))],
        );
        $table->render($frame, new Rect(0, 0, 40, 5), $context);

        self::assertSame('A', $frame->characters[1 * 40 + 0]);
        self::assertSame($greenStyle->foregroundValue(), $frame->foregrounds[1 * 40 + 0]);
    }

    #[Test]
    public function aHeaderlessTableStartsItsFirstRowAtTheTop(): void
    {
        $frame = new Frame(40, 5);
        $table = Table\Table::new(
            [Column::new('Name', Constraint::flex())],
            [Row::new('Alice'), Row::new('Bob')],
        )->withHeader(false);

        $table->render($frame, new Rect(0, 0, 40, 5), new RenderContext(Theme::dark()));

        self::assertSame('A', $frame->characters[0]);
        self::assertSame('B', $frame->characters[1 * 40]);
    }

    #[Test]
    public function aHeaderlessTableDoesNotDrawTheColumnTitles(): void
    {
        $table = Table\Table::fromCells(
            [Column::new('Name', Constraint::flex())],
            [['Alice']],
        );
        $area = new Rect(0, 0, 40, 5);

        self::assertStringContainsString('Name', $this->screenOfTable($table, $area));
        self::assertStringNotContainsString('Name', $this->screenOfTable($table->withHeader(false), $area));
    }

    #[Test]
    public function aHeaderlessTableGivesTheFreedRowToContent(): void
    {
        $withHeader = Table\Table::new(
            [Column::new('Name', Constraint::flex())],
            [Row::new('Alice')],
        );

        self::assertSame(2, $withHeader->measureHeight(40));
        self::assertSame(1, $withHeader->withHeader(false)->measureHeight(40));
    }

    #[Test]
    public function aHeaderlessTableMapsAClickToTheRowUnderIt(): void
    {
        $table = Table\Table::new(
            [Column::new('Name', Constraint::flex())],
            [Row::new('Alice'), Row::new('Bob'), Row::new('Carol')],
        )->withHeader(false)->withFocus(true);

        $clicked = $table->update(
            new MouseMsg(2, 1, MouseAction::Press, MouseButton::Left),
            new Rect(0, 0, 40, 5),
        )->model;

        self::assertInstanceOf(Table\Table::class, $clicked);
        self::assertSame(1, $clicked->cursor());
    }

    #[Test]
    public function aTokenCellUsesTheThemesStyle(): void
    {
        $frame = new Frame(40, 5);
        $theme = Theme::dark();
        $table = Table\Table::new(
            [Column::new('Name', Constraint::flex())],
            [Row::new(Span::token('Alice', StyleToken::Success))],
        );

        $table->render($frame, new Rect(0, 0, 40, 5), new RenderContext($theme));

        self::assertSame('A', $frame->characters[1 * 40 + 0]);
        self::assertSame($theme->success->foregroundValue(), $frame->foregrounds[1 * 40 + 0]);
    }

    #[Test]
    public function aTokenCellIsStillReversedOnTheFocusedCursorRow(): void
    {
        $frame = new Frame(40, 5);
        $theme = Theme::dark();
        $table = Table\Table::new(
            [Column::new('Name', Constraint::flex())],
            [Row::new(Span::token('Alice', StyleToken::Success))],
        )->withFocus(true);

        $table->render($frame, new Rect(0, 0, 40, 5), RenderContext::focused($theme));

        self::assertSame($theme->cursorStyle->backgroundValue(), $frame->backgrounds[1 * 40 + 0]);
        self::assertSame(
            $theme->success->readableOn($theme->cursorStyle)->foregroundValue(),
            $frame->foregrounds[1 * 40 + 0],
            'the token keeps its colour, lifted far enough off the band to still be read',
        );
    }

    #[Test]
    public function initialCursorSkipsHeaderRows(): void
    {
        $table = Table\Table::new(
            [Column::new('Name', Constraint::flex())],
            [Row::header('Group'), Row::new('Alice')],
        );

        self::assertSame(1, $table->cursor());
    }

    // --- L36: DecrementInteger — selected starts at -1 (no selection) ---

    #[Test]
    public function selectedStartsAtMinusOne(): void
    {
        $table = $this->createTable();
        self::assertSame(-1, $table->selectedIndex());
    }

    // --- L59: DecrementInteger — fromRows initial cursor = 0 when first row is selectable ---

    #[Test]
    public function fromRowsInitialCursorIsZeroWhenFirstRowSelectable(): void
    {
        $table = Table\Table::new(
            [Column::new('Name', Constraint::flex())],
            [Row::new('Alice'), Row::new('Bob')],
        );
        self::assertSame(0, $table->cursor());
    }

    #[Test]
    public function fromRowsInitialCursorSkipsMultipleHeaders(): void
    {
        $table = Table\Table::new(
            [Column::new('Name', Constraint::flex())],
            [Row::header('H1'), Row::header('H2'), Row::new('Alice')],
        );
        self::assertSame(2, $table->cursor());
    }

    // --- L115: GreaterThan — rowCount > 0 guard for key handling ---

    #[Test]
    public function focusedEmptyTableIgnoresAllKeys(): void
    {
        $table = Table\Table::fromCells([Column::new('ID', Constraint::exact(5))], [])->withFocus(true);
        $area = Rect::fromSize(20, 5);

        foreach ([Key::Up, Key::Down, Key::Home, Key::End, Key::PageDown, Key::PageUp, Key::Enter] as $key) {
            $result = $table->update(new KeyMsg($key), $area);
            self::assertSame($table, $result->model, "Key {$key->name} should be ignored on empty table");
        }
    }

    #[Test]
    public function singleRowTableHandlesKeys(): void
    {
        $table = Table\Table::fromCells(
            [Column::new('ID', Constraint::exact(5))],
            [['1']],
        )->withFocus(true);
        $area = Rect::fromSize(20, 5);

        $result = $table->update(new KeyMsg(Key::Enter), $area);
        self::assertSame(0, $result->model->selectedIndex());
    }

    // --- L123: LessThanOrEqualTo — render guard width <= 0 || height <= 0 ---

    #[Test]
    public function renderWithZeroHeightDoesNothing(): void
    {
        $table = $this->createTable();
        $frame = new Frame(20, 5);
        $table->render($frame, new Rect(0, 0, 20, 0), new RenderContext(Theme::dark()));
        self::assertSame(' ', $frame->characters[0]);
    }

    #[Test]
    public function renderWithWidthOneRendersContent(): void
    {
        $table = Table\Table::fromCells(
            [Column::new('X', Constraint::exact(1))],
            [['A']],
        );
        $frame = new Frame(5, 5);
        $table->render($frame, new Rect(0, 0, 1, 3), new RenderContext(Theme::dark()));
        self::assertSame('X', $frame->characters[0]);
    }

    // --- L129-141: visibleRows, showScrollbar, columnWidths, scrollbar position ---

    #[Test]
    public function visibleRowsIsHeightMinusOneForHeader(): void
    {
        $rows = array_map(fn (int $index) => [(string)$index], range(1, 10));
        $table = Table\Table::fromCells(
            [Column::new('ID', Constraint::exact(5))],
            $rows,
        );
        $frame = new Frame(20, 5);
        $table->render($frame, new Rect(0, 0, 20, 5), new RenderContext(Theme::dark()));

        $renderedRowCount = 0;
        for ($row = 1; $row < 5; ++$row) {
            $char = $frame->characters[$row * 20];
            if ($char !== ' ') {
                ++$renderedRowCount;
            }
        }
        self::assertSame(4, $renderedRowCount);
    }

    #[Test]
    public function scrollbarReducesColumnWidth(): void
    {
        $rows = array_map(fn (int $index) => [(string)$index], range(1, 20));
        $table = Table\Table::fromCells(
            [Column::new('ID', Constraint::flex())],
            $rows,
        );
        $bufferWithScroll = new Frame(20, 5);
        $table->render($bufferWithScroll, new Rect(0, 0, 20, 5), new RenderContext(Theme::dark()));

        $tableNoScroll = Table\Table::fromCells(
            [Column::new('ID', Constraint::flex())],
            [['1']],
        );
        $bufferNoScroll = new Frame(20, 5);
        $tableNoScroll->render($bufferNoScroll, new Rect(0, 0, 20, 5), new RenderContext(Theme::dark()));

        $scrollbarCol = $bufferWithScroll->characters[1 * 20 + 19];
        self::assertContains($scrollbarCol, [Scrollbar::THUMB, '│']);
    }

    #[Test]
    public function scrollbarPositionIsAtRightEdge(): void
    {
        $rows = array_map(fn (int $index) => [(string)$index], range(1, 20));
        $table = Table\Table::fromCells(
            [Column::new('ID', Constraint::flex())],
            $rows,
        );
        $frame = new Frame(30, 5);
        $area = new Rect(5, 2, 20, 5);
        $table->render($frame, $area, new RenderContext(Theme::dark()));

        $scrollbarX = 5 + 20 - 1;
        $hasScrollChar = false;
        for ($row = 3; $row < 7; ++$row) {
            $char = $frame->characters[$row * 30 + $scrollbarX];
            if ($char === Scrollbar::THUMB || $char === '│') {
                $hasScrollChar = true;
                break;
            }
        }
        self::assertTrue($hasScrollChar, 'Scrollbar should be at area.x + area.width - 1');
    }

    #[Test]
    public function scrollbarStartsAtYPlusOne(): void
    {
        $rows = array_map(fn (int $index) => [(string)$index], range(1, 20));
        $table = Table\Table::fromCells(
            [Column::new('ID', Constraint::flex())],
            $rows,
        );
        $frame = new Frame(30, 10);
        $area = new Rect(0, 0, 30, 10);
        $table->render($frame, $area, new RenderContext(Theme::dark()));

        $headerScrollbarChar = $frame->characters[0 * 30 + 29];
        self::assertSame(' ', $headerScrollbarChar, 'Header row should not have scrollbar');

        $firstDataScrollbarChar = $frame->characters[1 * 30 + 29];
        self::assertContains($firstDataScrollbarChar, [Scrollbar::THUMB, '│'], 'Scrollbar should start at y+1');
    }

    // --- L161-176: isRowSelectable, findNextSelectable null-safe, direction logic ---

    #[Test]
    public function isRowSelectableReturnsTrueForPlainRows(): void
    {
        $table = $this->createTable()->withFocus(true);
        $area = Rect::fromSize(60, 10);

        $result = $table->update(new KeyMsg(Key::Down), $area);
        self::assertSame(1, $result->model->cursor());

        $result = $result->model->update(new KeyMsg(Key::Down), $area);
        self::assertSame(2, $result->model->cursor());
    }

    #[Test]
    public function findNextSelectableReturnsNullAtBoundary(): void
    {
        $table = Table\Table::new(
            [Column::new('Name', Constraint::flex())],
            [Row::new('Alice'), Row::header('Group')],
        )->withFocus(true);
        $area = Rect::fromSize(40, 10);

        $result = $table->update(new KeyMsg(Key::Down), $area);
        self::assertSame(0, $result->model->cursor());
    }

    #[Test]
    public function findNextSelectableTraversesMultipleNonSelectable(): void
    {
        $table = Table\Table::new(
            [Column::new('Name', Constraint::flex())],
            [Row::new('Alice'), Row::header('H1'), Row::header('H2'), Row::new('Bob')],
        )->withFocus(true);
        $area = Rect::fromSize(40, 10);

        $result = $table->update(new KeyMsg(Key::Down), $area);
        self::assertSame(3, $result->model->cursor());
    }

    #[Test]
    public function findNextSelectableGoesBackward(): void
    {
        $table = Table\Table::new(
            [Column::new('Name', Constraint::flex())],
            [Row::new('Alice'), Row::header('H1'), Row::header('H2'), Row::new('Bob')],
        )->withFocus(true);
        $area = Rect::fromSize(40, 10);

        $result = $table->update(new KeyMsg(Key::Down), $area);
        self::assertSame(3, $result->model->cursor());

        $result = $result->model->update(new KeyMsg(Key::Up), $area);
        self::assertSame(0, $result->model->cursor());
    }

    // --- L185-219: handleKey scroll math, viewport, match arms ---

    #[Test]
    public function spaceSelectsTheRowUnderTheCursor(): void
    {
        $table = $this->createTable()->withFocus(true);
        $area = Rect::fromSize(60, 10);

        $result = $table->update(new KeyMsg(' '), $area);
        self::assertSame(0, $result->model->selectedIndex());

        $result = $result->model->update(new KeyMsg(' '), $area);
        self::assertSame(0, $result->model->selectedIndex(), 'space shares the choose action with enter');
    }

    #[Test]
    public function pressingEnterOnAChosenRowKeepsItChosen(): void
    {
        $table = $this->createTable()->withFocus(true);
        $area = Rect::fromSize(60, 10);

        $result = $table->update(new KeyMsg(Key::Enter), $area);
        self::assertSame(0, $result->model->selectedIndex());

        $result = $result->model->update(new KeyMsg(Key::Enter), $area);
        self::assertSame(0, $result->model->selectedIndex(), 'the hint says choose, and choosing twice does not unchoose');
    }

    #[Test]
    public function homeAtTopReturnsSelf(): void
    {
        $table = $this->createTable()->withFocus(true);
        $area = Rect::fromSize(60, 10);
        $result = $table->update(new KeyMsg(Key::Home), $area);
        self::assertSame($table, $result->model);
    }

    #[Test]
    public function endAtBottomReturnsSelf(): void
    {
        $table = $this->createTable()->withFocus(true);
        $area = Rect::fromSize(60, 10);
        $result = $table->update(new KeyMsg(Key::End), $area);
        $endTable = $result->model;
        $result = $endTable->update(new KeyMsg(Key::End), $area);
        self::assertSame($endTable, $result->model);
    }

    #[Test]
    public function pageDownExactCursorValue(): void
    {
        $rows = array_map(fn (int $index) => [(string)$index], range(1, 20));
        $table = Table\Table::fromCells(
            [Column::new('ID', Constraint::exact(5))],
            $rows,
        )->withFocus(true);
        $area = Rect::fromSize(20, 6);

        $result = $table->update(new KeyMsg(Key::PageDown), $area);
        self::assertSame(5, $result->model->cursor());
    }

    #[Test]
    public function aPageIsAViewportOfLinesEvenWhenRowsWrap(): void
    {
        // Every cell wraps onto two lines, so five viewport lines hold two rows
        // and a half — a page is two rows, not five.
        $rows = array_map(static fn (int $index): array => ["row $index wraps here"], range(1, 20));
        $table = Table\Table::fromCells([Column::new('Text', Constraint::exact(10))], $rows)->withFocus(true);
        $area = Rect::fromSize(10, 6);

        $result = $table->update(new KeyMsg(Key::PageDown), $area);

        self::assertSame(2, $result->model->cursor(), 'Not five, which would be more than a screenful');
    }

    #[Test]
    public function pageUpExactCursorValue(): void
    {
        $rows = array_map(fn (int $index) => [(string)$index], range(1, 20));
        $table = Table\Table::fromCells(
            [Column::new('ID', Constraint::exact(5))],
            $rows,
        )->withFocus(true);
        $area = Rect::fromSize(20, 6);

        $result = $table->update(new KeyMsg(Key::PageDown), $area);
        $result = $result->model->update(new KeyMsg(Key::PageDown), $area);
        self::assertSame(10, $result->model->cursor());

        $result = $result->model->update(new KeyMsg(Key::PageUp), $area);
        self::assertSame(5, $result->model->cursor());
    }

    #[Test]
    public function pageDownClampsToLastRow(): void
    {
        $rows = array_map(fn (int $index) => [(string)$index], range(1, 5));
        $table = Table\Table::fromCells(
            [Column::new('ID', Constraint::exact(5))],
            $rows,
        )->withFocus(true);
        $area = Rect::fromSize(20, 4);

        $result = $table->update(new KeyMsg(Key::PageDown), $area);
        $result = $result->model->update(new KeyMsg(Key::PageDown), $area);
        self::assertSame(4, $result->model->cursor());
    }

    #[Test]
    public function pageUpClampsToZero(): void
    {
        $rows = array_map(fn (int $index) => [(string)$index], range(1, 20));
        $table = Table\Table::fromCells(
            [Column::new('ID', Constraint::exact(5))],
            $rows,
        )->withFocus(true);
        $area = Rect::fromSize(20, 6);

        $result = $table->update(new KeyMsg(Key::PageDown), $area);
        $result = $result->model->update(new KeyMsg(Key::PageUp), $area);
        self::assertSame(0, $result->model->cursor());
    }

    #[Test]
    public function pageDownAtEndReturnsSelf(): void
    {
        $table = Table\Table::fromCells(
            [Column::new('ID', Constraint::exact(5))],
            [['1'], ['2']],
        )->withFocus(true);
        $area = Rect::fromSize(20, 10);

        $result = $table->update(new KeyMsg(Key::End), $area);
        $endTable = $result->model;
        $result = $endTable->update(new KeyMsg(Key::PageDown), $area);
        self::assertSame($endTable, $result->model);
    }

    #[Test]
    public function pageUpAtTopReturnsSelf(): void
    {
        $table = $this->createTable()->withFocus(true);
        $area = Rect::fromSize(60, 10);
        $result = $table->update(new KeyMsg(Key::PageUp), $area);
        self::assertSame($table, $result->model);
    }

    #[Test]
    public function defaultKeyReturnsSelf(): void
    {
        $table = $this->createTable()->withFocus(true);
        $area = Rect::fromSize(60, 10);
        $result = $table->update(new KeyMsg('x'), $area);
        self::assertSame($table, $result->model);
    }

    #[Test]
    public function unknownMessageReturnsSelf(): void
    {
        $table = $this->createTable()->withFocus(true);
        $area = Rect::fromSize(60, 10);
        $result = $table->update(new TestMessage('random string'), $area);
        self::assertSame($table, $result->model);
    }

    // --- L185: visibleRows = max(1, area.height - 1) ---

    #[Test]
    public function visibleRowsMinimumIsOneForSmallArea(): void
    {
        $table = Table\Table::fromCells(
            [Column::new('ID', Constraint::exact(5))],
            [['1'], ['2'], ['3']],
        )->withFocus(true);
        $area = Rect::fromSize(20, 1);

        $result = $table->update(new KeyMsg(Key::PageDown), $area);
        self::assertSame(1, $result->model->cursor());
    }

    // --- Typed rows: Home/End with selectable ---

    #[Test]
    public function typedRowsHomeMovesToFirstSelectable(): void
    {
        $table = Table\Table::new(
            [Column::new('Name', Constraint::flex())],
            [Row::header('H'), Row::new('Alice'), Row::new('Bob')],
        )->withFocus(true);
        $area = Rect::fromSize(40, 10);

        $result = $table->update(new KeyMsg(Key::End), $area);
        self::assertSame(2, $result->model->cursor());

        $result = $result->model->update(new KeyMsg(Key::Home), $area);
        self::assertSame(1, $result->model->cursor());
    }

    #[Test]
    public function typedRowsEndMovesToLastSelectable(): void
    {
        $table = Table\Table::new(
            [Column::new('Name', Constraint::flex())],
            [Row::new('Alice'), Row::new('Bob'), Row::header('Footer')],
        )->withFocus(true);
        $area = Rect::fromSize(40, 10);

        $result = $table->update(new KeyMsg(Key::End), $area);
        self::assertSame(1, $result->model->cursor());
    }

    #[Test]
    public function typedRowsHomeAtFirstSelectableReturnsSelf(): void
    {
        $table = Table\Table::new(
            [Column::new('Name', Constraint::flex())],
            [Row::header('H'), Row::new('Alice'), Row::new('Bob')],
        )->withFocus(true);
        $area = Rect::fromSize(40, 10);

        self::assertSame(1, $table->cursor());
        $result = $table->update(new KeyMsg(Key::Home), $area);
        self::assertSame($table, $result->model);
    }

    #[Test]
    public function typedRowsEndAtLastSelectableReturnsSelf(): void
    {
        $table = Table\Table::new(
            [Column::new('Name', Constraint::flex())],
            [Row::new('Alice'), Row::new('Bob'), Row::header('Footer')],
        )->withFocus(true);
        $area = Rect::fromSize(40, 10);

        $result = $table->update(new KeyMsg(Key::End), $area);
        $endTable = $result->model;
        $result = $endTable->update(new KeyMsg(Key::End), $area);
        self::assertSame($endTable, $result->model);
    }

    #[Test]
    public function typedRowsSpaceSelectsTheRowUnderTheCursor(): void
    {
        $table = Table\Table::new(
            [Column::new('Name', Constraint::flex())],
            [Row::new('Alice'), Row::new('Bob')],
        )->withFocus(true);
        $area = Rect::fromSize(40, 10);

        $result = $table->update(new KeyMsg(' '), $area);
        self::assertSame(0, $result->model->selectedIndex());

        $result = $result->model->update(new KeyMsg(' '), $area);
        self::assertSame(0, $result->model->selectedIndex(), 'space shares the choose action with enter');
    }

    #[Test]
    public function typedRowsDefaultKeyReturnsSelf(): void
    {
        $table = Table\Table::new(
            [Column::new('Name', Constraint::flex())],
            [Row::new('Alice')],
        )->withFocus(true);
        $area = Rect::fromSize(40, 10);

        $result = $table->update(new KeyMsg('x'), $area);
        self::assertSame($table, $result->model);
    }

    // --- L231-251: handlePageDown/handlePageUp with typed rows ---

    #[Test]
    public function typedRowsPageDownSkipsNonSelectable(): void
    {
        $table = Table\Table::new(
            [Column::new('Name', Constraint::flex())],
            [
                Row::new('Alice'),
                Row::header('H1'),
                Row::header('H2'),
                Row::new('Bob'),
                Row::header('H3'),
                Row::new('Charlie'),
            ],
        )->withFocus(true);
        $area = Rect::fromSize(40, 4);

        $result = $table->update(new KeyMsg(Key::PageDown), $area);
        self::assertTrue($result->model->cursor() > 0);
        self::assertContains($result->model->cursor(), [3, 5]);
    }

    #[Test]
    public function typedRowsPageUpSkipsNonSelectable(): void
    {
        $table = Table\Table::new(
            [Column::new('Name', Constraint::flex())],
            [
                Row::new('Alice'),
                Row::header('H1'),
                Row::header('H2'),
                Row::new('Bob'),
                Row::new('Charlie'),
            ],
        )->withFocus(true);
        $area = Rect::fromSize(40, 4);

        $result = $table->update(new KeyMsg(Key::End), $area);
        $result = $result->model->update(new KeyMsg(Key::PageUp), $area);
        self::assertContains($result->model->cursor(), [0, 3]);
    }

    #[Test]
    public function typedRowsPageDownAtEndReturnsSelf(): void
    {
        $table = Table\Table::new(
            [Column::new('Name', Constraint::flex())],
            [Row::new('Alice'), Row::new('Bob')],
        )->withFocus(true);
        $area = Rect::fromSize(40, 10);

        $result = $table->update(new KeyMsg(Key::End), $area);
        $endTable = $result->model;
        $result = $endTable->update(new KeyMsg(Key::PageDown), $area);
        self::assertSame($endTable, $result->model);
    }

    #[Test]
    public function typedRowsPageUpAtTopReturnsSelf(): void
    {
        $table = Table\Table::new(
            [Column::new('Name', Constraint::flex())],
            [Row::new('Alice'), Row::new('Bob')],
        )->withFocus(true);
        $area = Rect::fromSize(40, 10);

        $result = $table->update(new KeyMsg(Key::PageUp), $area);
        self::assertSame($table, $result->model);
    }

    // --- L256-317: computeColumnWidths ---

    #[Test]
    public function computeColumnWidthsNoColumns(): void
    {
        $table = Table\Table::fromCells([], []);
        $frame = new Frame(20, 5);
        $table->render($frame, Rect::fromSize(20, 5), new RenderContext(Theme::dark()));
        self::assertSame(' ', $frame->characters[0]);
    }

    #[Test]
    public function computeColumnWidthsExactColumnsGetExactWidth(): void
    {
        $table = Table\Table::fromCells(
            [Column::new('AA', Constraint::exact(3)), Column::new('BB', Constraint::exact(4))],
            [['aaa', 'bbbb']],
        );
        $frame = new Frame(20, 5);
        $table->render($frame, Rect::fromSize(20, 3), new RenderContext(Theme::dark()));

        self::assertSame('A', $frame->characters[0]);
        self::assertSame('A', $frame->characters[1]);
        self::assertSame(' ', $frame->characters[2]);
        self::assertSame('B', $frame->characters[3]);
        self::assertSame('B', $frame->characters[4]);
    }

    #[Test]
    public function tableDistributesFlexWeightProportionally(): void
    {
        $table = Table\Table::fromCells(
            [Column::new('A', Constraint::flex(2)), Column::new('B', Constraint::flex(1))],
            [['AAAAAAAAAA', 'BBBBBBBBBB']],
        );
        $frame = new Frame(30, 3);
        $table->render($frame, Rect::fromSize(30, 3), new RenderContext(Theme::dark()));

        $headerRow = '';
        for ($column = 0; $column < 30; ++$column) {
            $headerRow .= $frame->characters[$column];
        }

        $aCount = 0;
        $bCount = 0;
        for ($column = 0; $column < 30; ++$column) {
            if ($frame->characters[$column] === 'A') {
                ++$aCount;
            }
            if ($frame->characters[$column] === 'B') {
                ++$bCount;
            }
        }
        self::assertSame(1, $aCount);
        self::assertSame(1, $bCount);

        $dataRow = '';
        for ($column = 0; $column < 30; ++$column) {
            $dataRow .= $frame->characters[30 + $column];
        }

        $firstBPosition = mb_strpos($dataRow, 'B');
        self::assertNotFalse($firstBPosition);
        self::assertSame(20, $firstBPosition);
    }

    #[Test]
    public function flexColumnsUseRemainingAfterExact(): void
    {
        $table = Table\Table::fromCells(
            [Column::new('Fixed', Constraint::exact(10)), Column::new('Flex', Constraint::flex())],
            [['FIXED_____', 'FLEX_DATA_']],
        );
        $frame = new Frame(30, 3);
        $table->render($frame, Rect::fromSize(30, 3), new RenderContext(Theme::dark()));

        $dataRow = '';
        for ($column = 0; $column < 30; ++$column) {
            $dataRow .= $frame->characters[30 + $column];
        }

        self::assertSame('F', $frame->characters[30]);
        self::assertSame('F', $frame->characters[30 + 10]);
    }

    #[Test]
    public function flexColumnRemainderGoesToLastFlex(): void
    {
        $table = Table\Table::fromCells(
            [Column::new('A', Constraint::flex(1)), Column::new('B', Constraint::flex(1)), Column::new('C', Constraint::flex(1))],
            [['AAAAAAAAAA', 'BBBBBBBBBB', 'CCCCCCCCCC']],
        );
        $frame = new Frame(31, 3);
        $table->render($frame, Rect::fromSize(31, 3), new RenderContext(Theme::dark()));

        $dataRow = '';
        for ($column = 0; $column < 31; ++$column) {
            $dataRow .= $frame->characters[31 + $column];
        }

        $lastNonSpace = -1;
        for ($column = 30; $column >= 0; --$column) {
            if ($frame->characters[31 + $column] !== ' ') {
                $lastNonSpace = $column;
                break;
            }
        }
        self::assertGreaterThanOrEqual(9, $lastNonSpace);
    }

    #[Test]
    public function exactColumnSubtractsFromRemaining(): void
    {
        $table = Table\Table::fromCells(
            [Column::new('A', Constraint::exact(5)), Column::new('B', Constraint::flex(1))],
            [['12345', 'FLEX']],
        );
        $frame = new Frame(20, 3);
        $table->render($frame, Rect::fromSize(20, 3), new RenderContext(Theme::dark()));

        self::assertSame('1', $frame->characters[20 + 0]);
        self::assertSame('2', $frame->characters[20 + 1]);
        self::assertSame('3', $frame->characters[20 + 2]);
        self::assertSame('4', $frame->characters[20 + 3]);
        self::assertSame('5', $frame->characters[20 + 4]);
        self::assertSame('F', $frame->characters[20 + 5]);
    }

    #[Test]
    public function columnWidthDistributionUsesFloor(): void
    {
        $table = Table\Table::fromCells(
            [Column::new('A', Constraint::flex(1)), Column::new('B', Constraint::flex(1))],
            [['AAAAAAAAA', 'BBBBBBBBB']],
        );
        $frame = new Frame(11, 3);
        $table->render($frame, Rect::fromSize(11, 3), new RenderContext(Theme::dark()));

        $firstBPosition = -1;
        for ($column = 0; $column < 11; ++$column) {
            if ($frame->characters[11 + $column] === 'B') {
                $firstBPosition = $column;
                break;
            }
        }
        self::assertSame(5, $firstBPosition);

        $lastBPosition = -1;
        for ($column = 10; $column >= 0; --$column) {
            if ($frame->characters[11 + $column] === 'B') {
                $lastBPosition = $column;
                break;
            }
        }
        self::assertSame(10, $lastBPosition);
    }

    // --- L331-468: Cell rendering, truncation ---

    #[Test]
    public function headerTruncation(): void
    {
        $table = Table\Table::fromCells(
            [Column::new('VeryLongHeaderName', Constraint::exact(5))],
            [['data']],
        );
        $frame = new Frame(20, 3);
        $table->render($frame, Rect::fromSize(20, 3), new RenderContext(Theme::dark()));

        $header = '';
        for ($column = 0; $column < 5; ++$column) {
            $header .= $frame->characters[$column];
        }
        self::assertSame('VeryL', $header);
        self::assertSame(' ', $frame->characters[5]);
    }

    #[Test]
    public function cellValueTruncatedToColumnWidth(): void
    {
        $table = Table\Table::fromCells(
            [Column::new('ID', Constraint::exact(3))],
            [['ABCDEFGH']],
        );
        $frame = new Frame(20, 3);
        $table->render($frame, Rect::fromSize(20, 3), new RenderContext(Theme::dark()));

        self::assertSame('A', $frame->characters[20 + 0]);
        self::assertSame('B', $frame->characters[20 + 1]);
        self::assertSame('C', $frame->characters[20 + 2]);
        self::assertSame(' ', $frame->characters[20 + 3]);
    }

    // --- fluid cells ---

    #[Test]
    public function longCellsWrapInsteadOfBeingCut(): void
    {
        $table = Table\Table::fromCells(
            [Column::new('Title', Constraint::exact(10))],
            [['wrap me over several lines']],
        );
        $frame = new Frame(20, 6);

        $table->render($frame, Rect::fromSize(20, 6), new RenderContext(Theme::dark()));

        self::assertSame('wrap me', $this->rowText($frame, 20, 1, 0, 10), 'First line of the cell');
        self::assertSame('over', $this->rowText($frame, 20, 2, 0, 10), 'And the rest follows below');
        self::assertSame('several', $this->rowText($frame, 20, 3, 0, 10));
        self::assertSame('lines', $this->rowText($frame, 20, 4, 0, 10), 'Nothing is cut off');
    }

    #[Test]
    public function aWrappedRowPushesTheNextRowDown(): void
    {
        $table = Table\Table::fromCells(
            [Column::new('Title', Constraint::exact(10))],
            [['one two three'], ['second']],
        );
        $frame = new Frame(20, 6);

        $table->render($frame, Rect::fromSize(20, 6), new RenderContext(Theme::dark()));

        self::assertSame('second', $this->rowText($frame, 20, 3, 0, 6), 'The second row starts below all of the first');
    }

    #[Test]
    public function theSelectionCoversEveryLineOfAWrappedRow(): void
    {
        $table = Table\Table::fromCells(
            [Column::new('Title', Constraint::exact(10))],
            [['one two three'], ['second']],
        )->withFocus(true)->withCursor(0);
        $frame = new Frame(20, 6);

        $theme = Theme::dark();
        $table->render($frame, Rect::fromSize(20, 6), RenderContext::focused($theme));

        $band = $theme->cursorStyle->backgroundValue();
        self::assertSame($band, $frame->backgrounds[20 * 1], 'The highlight starts on the first line');
        self::assertSame($band, $frame->backgrounds[20 * 2], 'And covers the wrapped line too');
    }

    #[Test]
    public function clickingAWrappedLineSelectsItsOwnRow(): void
    {
        $table = Table\Table::fromCells(
            [Column::new('Title', Constraint::exact(10))],
            [['one two three'], ['second']],
        )->withFocus(true);
        $area = Rect::fromSize(20, 6);

        // "one two three" wraps to two lines, so screen row 2 is still row 0.
        $result = $table->update(new MouseMsg(2, 2, MouseAction::Press, MouseButton::Left), $area);

        self::assertInstanceOf(Table\Table::class, $result->model);
        self::assertSame(0, $result->model->cursor());
    }

    #[Test]
    public function measuresItsHeightFromWrappedRows(): void
    {
        $table = Table\Table::fromCells(
            [Column::new('Title', Constraint::exact(10))],
            [['one two three'], ['second']],
        );

        // Header, two lines for the first row, one for the second.
        self::assertSame(4, $table->measureHeight(20));
    }

    #[Test]
    public function columnWithZeroComputedWidthSkipsRendering(): void
    {
        $table = Table\Table::fromCells(
            [Column::new('A', Constraint::exact(3)), Column::new('B', Constraint::flex())],
            [['AAA', 'BBB']],
        );
        $frame = new Frame(20, 3);
        $table->render($frame, new Rect(0, 0, 3, 3), new RenderContext(Theme::dark()));

        self::assertSame('A', $frame->characters[0]);
        self::assertSame('A', $frame->characters[20 + 0]);
        self::assertSame('A', $frame->characters[20 + 2]);
        self::assertSame(' ', $frame->characters[20 + 3]);
    }

    #[Test]
    public function headerCellXAdvances(): void
    {
        $table = Table\Table::fromCells(
            [Column::new('AA', Constraint::exact(4)), Column::new('BB', Constraint::exact(4))],
            [['1111', '2222']],
        );
        $frame = new Frame(20, 3);
        $table->render($frame, Rect::fromSize(20, 3), new RenderContext(Theme::dark()));

        self::assertSame('A', $frame->characters[0]);
        self::assertSame('A', $frame->characters[1]);
        self::assertSame(' ', $frame->characters[2]);
        self::assertSame(' ', $frame->characters[3]);
        self::assertSame('B', $frame->characters[4]);
        self::assertSame('B', $frame->characters[5]);
    }

    #[Test]
    public function rowCellXAdvances(): void
    {
        $table = Table\Table::fromCells(
            [Column::new('A', Constraint::exact(3)), Column::new('B', Constraint::exact(3))],
            [['abc', 'def']],
        );
        $frame = new Frame(20, 3);
        $table->render($frame, Rect::fromSize(20, 3), new RenderContext(Theme::dark()));

        self::assertSame('a', $frame->characters[20 + 0]);
        self::assertSame('b', $frame->characters[20 + 1]);
        self::assertSame('c', $frame->characters[20 + 2]);
        self::assertSame('d', $frame->characters[20 + 3]);
        self::assertSame('e', $frame->characters[20 + 4]);
        self::assertSame('f', $frame->characters[20 + 5]);
    }

    // --- Render with offset area ---

    #[Test]
    public function renderAtOffset(): void
    {
        $table = Table\Table::fromCells(
            [Column::new('ID', Constraint::exact(3))],
            [['ABC']],
        );
        $frame = new Frame(20, 5);
        $table->render($frame, new Rect(2, 1, 10, 4), new RenderContext(Theme::dark()));

        self::assertSame('I', $frame->characters[1 * 20 + 2]);
        self::assertSame('D', $frame->characters[1 * 20 + 3]);
        self::assertSame('A', $frame->characters[2 * 20 + 2]);
    }

    // --- Scrolling with rendered rows ---

    #[Test]
    public function scrolledTableRendersCorrectRows(): void
    {
        $rows = array_map(fn (int $index) => [(string)$index], range(0, 9));
        $table = Table\Table::fromCells(
            [Column::new('ID', Constraint::exact(3))],
            $rows,
        )->withFocus(true);
        $area = Rect::fromSize(20, 4);

        for ($index = 0; $index < 5; ++$index) {
            $result = $table->update(new KeyMsg(Key::Down), $area);
            $table = $result->model;
        }

        $frame = new Frame(20, 4);
        $table->render($frame, $area, new RenderContext(Theme::dark()));

        self::assertSame('5', $frame->characters[3 * 20 + 0]);
    }

    // --- Cursor visibility (ensureCursorVisible) ---

    #[Test]
    public function cursorScrollsDownWhenBelowViewport(): void
    {
        $rows = array_map(fn (int $index) => [(string)$index], range(0, 19));
        $table = Table\Table::fromCells(
            [Column::new('ID', Constraint::exact(3))],
            $rows,
        )->withFocus(true);
        $area = Rect::fromSize(20, 4);

        for ($index = 0; $index < 5; ++$index) {
            $result = $table->update(new KeyMsg(Key::Down), $area);
            $table = $result->model;
        }

        self::assertSame(5, $table->cursor());
        self::assertGreaterThan(0, $table->scrollOffset());
    }

    #[Test]
    public function cursorScrollsUpWhenAboveViewport(): void
    {
        $rows = array_map(fn (int $index) => [(string)$index], range(0, 19));
        $table = Table\Table::fromCells(
            [Column::new('ID', Constraint::exact(3))],
            $rows,
        )->withFocus(true);
        $area = Rect::fromSize(20, 4);

        for ($index = 0; $index < 10; ++$index) {
            $result = $table->update(new KeyMsg(Key::Down), $area);
            $table = $result->model;
        }
        $scrollBefore = $table->scrollOffset();

        $result = $table->update(new KeyMsg(Key::Home), $area);
        $table = $result->model;

        self::assertSame(0, $table->cursor());
        self::assertSame(0, $table->scrollOffset());
        self::assertLessThan($scrollBefore, $table->scrollOffset());
    }

    #[Test]
    public function ensureCursorVisibleAdjustsScrollWhenCursorBelowViewport(): void
    {
        $rows = array_map(fn (int $index) => [(string)$index], range(0, 9));
        $table = Table\Table::fromCells(
            [Column::new('ID', Constraint::exact(3))],
            $rows,
        )->withFocus(true);
        $area = Rect::fromSize(20, 4);

        $result = $table->update(new KeyMsg(Key::End), $area);
        $table = $result->model;

        self::assertSame(9, $table->cursor());

        $visibleRows = 3;
        $expectedOffset = 9 - $visibleRows + 1;
        self::assertSame($expectedOffset, $table->scrollOffset());
    }

    #[Test]
    public function ensureCursorVisibleDoesNotChangeWhenAlreadyVisible(): void
    {
        $table = $this->createTable()->withFocus(true);
        $area = Rect::fromSize(60, 10);

        $result = $table->update(new KeyMsg(Key::Down), $area);
        self::assertSame(0, $result->model->scrollOffset());
    }

    #[Test]
    public function ensureCursorVisibleNoOpDoesNotChangeScrollOffset(): void
    {
        $table = $this->createTable()->withFocus(true);
        $area = Rect::fromSize(60, 10);

        $result = $table->update(new KeyMsg(Key::Down), $area);
        self::assertSame(0, $result->model->scrollOffset(), 'scrollOffset must stay 0 when cursor moves into an already-visible row');
        self::assertSame(1, $result->model->cursor());
    }

    // --- Mouse: click row index calculation ---

    #[Test]
    public function mouseClickCalculatesCorrectRowWithScrollOffset(): void
    {
        $rows = array_map(fn (int $index) => [(string)$index], range(0, 19));
        $table = Table\Table::fromCells(
            [Column::new('ID', Constraint::exact(5))],
            $rows,
        );
        $area = new Rect(0, 0, 20, 5);

        $wheelDown = new MouseMsg(5, 2, MouseAction::WheelDown, MouseButton::None);
        $result = $table->update($wheelDown, $area);
        $table = $result->model;
        self::assertSame(1, $table->scrollOffset());

        $click = new MouseMsg(5, 1, MouseAction::Press, MouseButton::Left);
        $result = $table->update($click, $area);

        self::assertSame(1, $result->model->cursor());
        self::assertSame(1, $result->model->selectedIndex());
    }

    #[Test]
    public function mouseClickOnLastVisibleRow(): void
    {
        $rows = array_map(fn (int $index) => [(string)$index], range(0, 19));
        $table = Table\Table::fromCells(
            [Column::new('ID', Constraint::exact(5))],
            $rows,
        );
        $area = new Rect(0, 0, 20, 5);

        $click = new MouseMsg(5, 4, MouseAction::Press, MouseButton::Left);
        $result = $table->update($click, $area);
        self::assertSame(3, $result->model->cursor());
    }

    #[Test]
    public function mouseClickOnNonSelectableTypedRowDoesNothing(): void
    {
        $table = Table\Table::new(
            [Column::new('Name', Constraint::flex())],
            [Row::header('Group'), Row::new('Alice')],
        );
        $area = new Rect(0, 0, 40, 5);

        $click = new MouseMsg(5, 1, MouseAction::Press, MouseButton::Left);
        $result = $table->update($click, $area);
        self::assertSame($table, $result->model);
    }

    // --- Mouse: wheel scroll bounds ---

    #[Test]
    public function mouseWheelDownClampsToMaxOffset(): void
    {
        $rows = array_map(fn (int $index) => [(string)$index], range(0, 5));
        $table = Table\Table::fromCells(
            [Column::new('ID', Constraint::exact(5))],
            $rows,
        );
        $area = new Rect(0, 0, 20, 4);

        for ($index = 0; $index < 20; ++$index) {
            $wheelDown = new MouseMsg(5, 2, MouseAction::WheelDown, MouseButton::None);
            $result = $table->update($wheelDown, $area);
            $table = $result->model;
        }

        $maxOffset = max(0, 6 - 3);
        self::assertSame($maxOffset, $table->scrollOffset());
    }

    #[Test]
    public function mouseWheelUpClampsToZero(): void
    {
        $rows = array_map(fn (int $index) => [(string)$index], range(0, 9));
        $table = Table\Table::fromCells(
            [Column::new('ID', Constraint::exact(5))],
            $rows,
        );
        $area = new Rect(0, 0, 20, 5);

        $wheelUp = new MouseMsg(5, 2, MouseAction::WheelUp, MouseButton::None);
        $result = $table->update($wheelUp, $area);
        self::assertSame(0, $result->model->scrollOffset());
    }

    #[Test]
    public function mouseWheelUpFromScrolledPosition(): void
    {
        $rows = array_map(fn (int $index) => [(string)$index], range(0, 9));
        $table = Table\Table::fromCells(
            [Column::new('ID', Constraint::exact(5))],
            $rows,
        );
        $area = new Rect(0, 0, 20, 5);

        $wheelDown = new MouseMsg(5, 2, MouseAction::WheelDown, MouseButton::None);
        $result = $table->update($wheelDown, $area);
        $result = $result->model->update($wheelDown, $area);
        self::assertSame(2, $result->model->scrollOffset());

        $wheelUp = new MouseMsg(5, 2, MouseAction::WheelUp, MouseButton::None);
        $result = $result->model->update($wheelUp, $area);
        self::assertSame(1, $result->model->scrollOffset());
    }

    #[Test]
    public function mouseWheelOutsideAreaDoesNothing(): void
    {
        $rows = array_map(fn (int $index) => [(string)$index], range(0, 9));
        $table = Table\Table::fromCells(
            [Column::new('ID', Constraint::exact(5))],
            $rows,
        );
        $area = new Rect(0, 0, 20, 5);

        $wheelDown = new MouseMsg(25, 2, MouseAction::WheelDown, MouseButton::None);
        $result = $table->update($wheelDown, $area);
        self::assertSame($table, $result->model);
    }

    #[Test]
    public function mouseReleaseDoesNothing(): void
    {
        $table = $this->createTable();
        $area = new Rect(0, 0, 60, 10);

        $release = new MouseMsg(5, 2, MouseAction::Release, MouseButton::Left);
        $result = $table->update($release, $area);
        self::assertSame($table, $result->model);
    }

    // --- Rendering: focused vs unfocused style ---

    #[Test]
    public function unfocusedTableUsesDimStyle(): void
    {
        $theme = Theme::dark();
        $table = Table\Table::fromCells(
            [Column::new('ID', Constraint::exact(5))],
            [['hello']],
        );
        $frame = new Frame(20, 3);
        $table->render($frame, Rect::fromSize(20, 3), new RenderContext($theme));

        self::assertSame($theme->dim->foregroundValue(), $frame->foregrounds[20]);
    }

    #[Test]
    public function focusedTableUsesBaseStyle(): void
    {
        $theme = Theme::dark();
        $table = Table\Table::fromCells(
            [Column::new('ID', Constraint::exact(5))],
            [['hello'], ['world']],
        )->withFocus(true);
        $frame = new Frame(20, 4);
        $table->render($frame, Rect::fromSize(20, 4), RenderContext::focused($theme));

        self::assertSame($theme->cursorStyle->backgroundValue(), $frame->backgrounds[20]);

        self::assertSame($theme->base->foregroundValue(), $frame->foregrounds[2 * 20]);
    }

    // --- Rendering: cursor row has reverse attribute ---

    #[Test]
    public function aRowAwayFromTheCursorIsNotHighlighted(): void
    {
        $table = $this->createTable()->withFocus(true);
        $frame = new Frame(60, 10);
        $theme = Theme::dark();
        $table->render($frame, Rect::fromSize(60, 10), new RenderContext($theme));

        self::assertNotSame($theme->cursorStyle->backgroundValue(), $frame->backgrounds[2 * 60]);
    }

    #[Test]
    public function selectedRowHasReverseEvenWhenNotFocused(): void
    {
        $table = $this->createTable()->withFocus(true);
        $area = Rect::fromSize(60, 10);

        $result = $table->update(new KeyMsg(Key::Down), $area);
        $result = $result->model->update(new KeyMsg(Key::Enter), $area);
        $table = $result->model->withFocus(false);

        $frame = new Frame(60, 10);
        $theme = Theme::dark();
        $table->render($frame, $area, new RenderContext($theme));

        self::assertSame($theme->selectedStyle->backgroundValue(), $frame->backgrounds[2 * 60]);
    }

    // --- The highlight fills totalWidth ---

    #[Test]
    public function theHighlightFillsEntireTotalWidth(): void
    {
        $table = Table\Table::fromCells(
            [Column::new('A', Constraint::exact(3)), Column::new('B', Constraint::exact(3))],
            [['abc', 'def']],
        )->withFocus(true);
        $frame = new Frame(20, 3);
        $theme = Theme::dark();
        $table->render($frame, Rect::fromSize(20, 3), RenderContext::focused($theme));

        for ($column = 0; $column < 6; ++$column) {
            self::assertSame(
                $theme->cursorStyle->backgroundValue(),
                $frame->backgrounds[20 + $column],
                "Column $column should carry the highlight"
            );
        }
    }

    // --- Typed row rendering: non-selectable header row ---

    #[Test]
    public function typedHeaderRowUsesHeaderStyle(): void
    {
        $theme = Theme::dark();
        $table = Table\Table::new(
            [Column::new('Name', Constraint::flex())],
            [Row::header('Group Title'), Row::new('Alice')],
        )->withFocus(true);
        $frame = new Frame(40, 5);
        $table->render($frame, Rect::fromSize(40, 5), new RenderContext($theme));

        self::assertSame('G', $frame->characters[40 + 0]);
        self::assertSame($theme->headerStyle->foregroundValue(), $frame->foregrounds[40 + 0]);
    }

    #[Test]
    public function aGroupHeaderWiderThanTheTableWrapsOntoASecondLine(): void
    {
        $table = Table\Table::new(
            [Column::new('N', Constraint::exact(3))],
            [Row::header('VeryLongGroupName')],
        );
        $frame = new Frame(20, 10);
        $table->render($frame, Rect::fromSize(20, 10), new RenderContext(Theme::dark()));

        self::assertSame('yLo', $this->rowText($frame, 20, 2, 0, 3));
    }

    #[Test]
    public function aGroupHeaderReservesOnlyTheLinesItWrapsToAcrossAllColumns(): void
    {
        $table = Table\Table::new(
            [Column::new('Name', Constraint::exact(6)), Column::new('Role', Constraint::exact(6))],
            [Row::header('Engineering department'), Row::new('Ada', 'dev')],
        );
        $frame = new Frame(20, 9);
        $table->render($frame, Rect::fromSize(20, 9), new RenderContext(Theme::dark()));

        self::assertSame('Ada   dev', $this->rowText($frame, 20, 3, 0, 9));
    }

    #[Test]
    public function aRowThatCannotBeSelectedStillShowsEveryColumn(): void
    {
        $table = Table\Table::new(
            [Column::new('Name', Constraint::exact(6)), Column::new('Role', Constraint::exact(6))],
            [Row::new('Ada', 'dev')->withSelectable(false)],
        );
        $frame = new Frame(20, 6);
        $table->render($frame, Rect::fromSize(20, 6), new RenderContext(Theme::dark()));

        self::assertSame('Ada   dev', $this->rowText($frame, 20, 1, 0, 9));
    }

    #[Test]
    public function aGroupHeaderBreaksAtTheTotalWidthAndSpillsIntoNoColumn(): void
    {
        $table = Table\Table::new(
            [Column::new('N', Constraint::exact(3))],
            [Row::header('VeryLongGroupName'), Row::new('A')],
        )->withScrollOffset(0);
        $frame = new Frame(20, 4);
        $table->render($frame, Rect::fromSize(20, 4), new RenderContext(Theme::dark()));

        self::assertSame('V', $frame->characters[20 + 0]);
        self::assertSame('e', $frame->characters[20 + 1]);
        self::assertSame('r', $frame->characters[20 + 2]);
        self::assertSame(' ', $frame->characters[20 + 3]);
    }

    // --- Typed row rendering: selectable with custom style ---

    #[Test]
    public function aRowWithItsOwnStyleStillTakesTheHighlightUnderIt(): void
    {
        $customStyle = new Style(foreground: Color::ansi(3));
        $table = Table\Table::new(
            [Column::new('Name', Constraint::flex())],
            [Row::new('Alice')->withStyle($customStyle)],
        )->withFocus(true);
        $frame = new Frame(40, 3);
        $theme = Theme::dark();
        $table->render($frame, Rect::fromSize(40, 3), RenderContext::focused($theme));

        self::assertSame(
            $theme->cursorStyle->backgroundValue(),
            $frame->backgrounds[40],
            'a row cannot punch a hole in the band it sits on',
        );
    }

    #[Test]
    public function typedRowNonCursorUsesRowStyle(): void
    {
        $customStyle = new Style(foreground: Color::ansi(3));
        $table = Table\Table::new(
            [Column::new('Name', Constraint::flex())],
            [Row::new('Alice'), Row::new('Bob')->withStyle($customStyle)],
        )->withFocus(true);
        $frame = new Frame(40, 4);
        $table->render($frame, Rect::fromSize(40, 4), new RenderContext(Theme::dark()));

        self::assertSame($customStyle->foregroundValue(), $frame->foregrounds[2 * 40]);
    }

    // --- Span rendering in typed rows ---

    #[Test]
    public function spanCellTruncatedToColumnWidth(): void
    {
        $greenStyle = new Style(foreground: Color::ansi(2));
        $table = Table\Table::new(
            [Column::new('Name', Constraint::exact(3))],
            [Row::new(Span::styled('ABCDEFGH', $greenStyle))],
        );
        $frame = new Frame(20, 3);
        $table->render($frame, Rect::fromSize(20, 3), new RenderContext(Theme::dark()));

        self::assertSame('A', $frame->characters[20 + 0]);
        self::assertSame('B', $frame->characters[20 + 1]);
        self::assertSame('C', $frame->characters[20 + 2]);
        self::assertSame(' ', $frame->characters[20 + 3]);
    }

    #[Test]
    public function spanWithStyleOnAHighlightedRow(): void
    {
        $greenStyle = new Style(foreground: Color::ansi(2));
        $table = Table\Table::new(
            [Column::new('Name', Constraint::flex())],
            [Row::new(Span::styled('Alice', $greenStyle))],
        )->withFocus(true);
        $frame = new Frame(40, 3);
        $theme = Theme::dark();
        $table->render($frame, Rect::fromSize(40, 3), RenderContext::focused($theme));

        self::assertSame($theme->cursorStyle->backgroundValue(), $frame->backgrounds[40]);
        self::assertSame(
            $greenStyle->readableOn($theme->cursorStyle)->foregroundValue(),
            $frame->foregrounds[40],
            'the span keeps its own colour, lifted off the band far enough to read',
        );
    }

    #[Test]
    public function spanWithoutStyleUsesRowStyle(): void
    {
        $customStyle = new Style(foreground: Color::ansi(3));
        $table = Table\Table::new(
            [Column::new('Name', Constraint::flex())],
            [Row::new(new Span('Alice'))->withStyle($customStyle)],
        );
        $frame = new Frame(40, 3);
        $table->render($frame, Rect::fromSize(40, 3), new RenderContext(Theme::dark()));

        self::assertSame($customStyle->foregroundValue(), $frame->foregrounds[40]);
    }

    #[Test]
    public function plainStringCellInTypedRow(): void
    {
        $table = Table\Table::new(
            [Column::new('Name', Constraint::flex())],
            [Row::new('Hello')],
        );
        $frame = new Frame(40, 3);
        $table->render($frame, Rect::fromSize(40, 3), new RenderContext(Theme::dark()));

        self::assertSame('H', $frame->characters[40 + 0]);
        self::assertSame('e', $frame->characters[40 + 1]);
    }

    // --- Multiple column rendering with typed rows ---

    #[Test]
    public function typedRowMultipleColumnCellXAdvances(): void
    {
        $table = Table\Table::new(
            [Column::new('A', Constraint::exact(3)), Column::new('B', Constraint::exact(3))],
            [Row::new('abc', 'def')],
        );
        $frame = new Frame(20, 3);
        $table->render($frame, Rect::fromSize(20, 3), new RenderContext(Theme::dark()));

        self::assertSame('a', $frame->characters[20 + 0]);
        self::assertSame('b', $frame->characters[20 + 1]);
        self::assertSame('c', $frame->characters[20 + 2]);
        self::assertSame('d', $frame->characters[20 + 3]);
        self::assertSame('e', $frame->characters[20 + 4]);
        self::assertSame('f', $frame->characters[20 + 5]);
    }

    #[Test]
    public function typedRowZeroWidthColumnSkipped(): void
    {
        $table = Table\Table::new(
            [Column::new('A', Constraint::exact(3)), Column::new('B', Constraint::flex())],
            [Row::new('AAA', 'BBB')],
        );
        $frame = new Frame(20, 3);
        $table->render($frame, new Rect(0, 0, 3, 3), new RenderContext(Theme::dark()));

        self::assertSame('A', $frame->characters[20 + 0]);
        self::assertSame(' ', $frame->characters[20 + 3]);
    }

    // --- Render rows: break when rowIndex >= rowCount ---

    #[Test]
    public function renderStopsAtLastRow(): void
    {
        $table = Table\Table::fromCells(
            [Column::new('ID', Constraint::exact(3))],
            [['A'], ['B']],
        );
        $frame = new Frame(20, 10);
        $table->render($frame, Rect::fromSize(20, 10), new RenderContext(Theme::dark()));

        self::assertSame('A', $frame->characters[1 * 20 + 0]);
        self::assertSame('B', $frame->characters[2 * 20 + 0]);
        self::assertSame(' ', $frame->characters[3 * 20 + 0]);
    }

    // --- renderY calculation ---

    #[Test]
    public function renderYStartsAtAreaYPlusOne(): void
    {
        $table = Table\Table::fromCells(
            [Column::new('ID', Constraint::exact(3))],
            [['X']],
        );
        $frame = new Frame(20, 10);
        $table->render($frame, new Rect(0, 3, 10, 5), new RenderContext(Theme::dark()));

        self::assertSame('I', $frame->characters[3 * 20 + 0]);
        self::assertSame('X', $frame->characters[4 * 20 + 0]);
    }

    // --- Missing cell value defaults to empty string ---

    #[Test]
    public function missingCellDefaultsToEmpty(): void
    {
        $table = Table\Table::fromCells(
            [Column::new('A', Constraint::exact(3)), Column::new('B', Constraint::exact(3))],
            [['only_a']],
        );
        $frame = new Frame(20, 3);
        $table->render($frame, Rect::fromSize(20, 3), new RenderContext(Theme::dark()));

        self::assertSame('o', $frame->characters[20 + 0]);
        self::assertSame(' ', $frame->characters[20 + 3]);
    }

    #[Test]
    public function typedRowMissingCellDefaultsToEmpty(): void
    {
        $table = Table\Table::new(
            [Column::new('A', Constraint::exact(3)), Column::new('B', Constraint::exact(3))],
            [Row::new('abc')],
        );
        $frame = new Frame(20, 3);
        $table->render($frame, Rect::fromSize(20, 3), new RenderContext(Theme::dark()));

        self::assertSame('a', $frame->characters[20 + 0]);
        self::assertSame(' ', $frame->characters[20 + 3]);
    }

    // --- scrollOffset accessor ---

    #[Test]
    public function scrollOffsetStartsAtZero(): void
    {
        self::assertSame(0, $this->createTable()->scrollOffset());
    }

    // --- rowCount uses typedRows when available ---

    #[Test]
    public function rowCountUsesTypedRowsWhenPresent(): void
    {
        $table = Table\Table::new(
            [Column::new('Name', Constraint::flex())],
            [Row::new('A'), Row::new('B'), Row::new('C')],
        )->withFocus(true);
        $area = Rect::fromSize(40, 10);

        $result = $table->update(new KeyMsg(Key::End), $area);
        self::assertSame(2, $result->model->cursor());
    }

    // --- mb_substr for unicode header truncation ---

    #[Test]
    public function headerTruncationWithMultibyteCharacters(): void
    {
        $table = Table\Table::fromCells(
            [Column::new("\xC3\xBCber", Constraint::exact(3))],
            [['x']],
        );
        $frame = new Frame(20, 3);
        $table->render($frame, Rect::fromSize(20, 3), new RenderContext(Theme::dark()));

        $header = $frame->characters[0] . $frame->characters[1] . $frame->characters[2];
        self::assertSame("\xC3\xBCbe", $header);
    }

    #[Test]
    public function cellTruncationWithMultibyteCharacters(): void
    {
        $table = Table\Table::fromCells(
            [Column::new('ID', Constraint::exact(3))],
            [["\xC3\xBCbertragen"]],
        );
        $frame = new Frame(20, 3);
        $table->render($frame, Rect::fromSize(20, 3), new RenderContext(Theme::dark()));

        $cell = $frame->characters[20 + 0] . $frame->characters[20 + 1] . $frame->characters[20 + 2];
        self::assertSame("\xC3\xBCbe", $cell);
    }

    // --- Typed row with Span: mb_substr truncation ---

    #[Test]
    public function spanCellTruncationWithMultibyteCharacters(): void
    {
        $style = new Style(foreground: Color::ansi(1));
        $table = Table\Table::new(
            [Column::new('Name', Constraint::exact(3))],
            [Row::new(Span::styled("\xC3\xBCbertragen", $style))],
        );
        $frame = new Frame(20, 3);
        $table->render($frame, Rect::fromSize(20, 3), new RenderContext(Theme::dark()));

        $cell = $frame->characters[20 + 0] . $frame->characters[20 + 1] . $frame->characters[20 + 2];
        self::assertSame("\xC3\xBCbe", $cell);
    }

    // --- selectedRow returns correct data ---

    #[Test]
    public function selectedRowReturnsCorrectData(): void
    {
        $table = $this->createTable()->withFocus(true);
        $area = Rect::fromSize(60, 10);

        $result = $table->update(new KeyMsg(Key::Enter), $area);
        self::assertSame(['1', 'Alice', 'Active'], $result->model->selectedRow());
    }

    // --- Mouse: localY and rowIndex computation ---

    #[Test]
    public function mouseClickAtAreaOffsetComputesCorrectRow(): void
    {
        $table = $this->createTable();
        $area = new Rect(5, 3, 60, 10);

        $click = new MouseMsg(10, 5, MouseAction::Press, MouseButton::Left);
        $result = $table->update($click, $area);

        self::assertSame(1, $result->model->cursor());
        self::assertSame(1, $result->model->selectedIndex());
    }

    #[Test]
    public function mouseClickBeyondRowCountDoesNothing(): void
    {
        $table = Table\Table::fromCells(
            [Column::new('ID', Constraint::exact(5))],
            [['1'], ['2']],
        );
        $area = new Rect(0, 0, 60, 10);

        $click = new MouseMsg(5, 5, MouseAction::Press, MouseButton::Left);
        $result = $table->update($click, $area);
        self::assertSame($table, $result->model);
    }

    // --- Mouse: WheelDown maxOffset uses (height - 1) ---

    #[Test]
    public function mouseWheelDownMaxOffsetUsesHeightMinusOne(): void
    {
        $rows = [['1'], ['2'], ['3'], ['4'], ['5']];
        $table = Table\Table::fromCells(
            [Column::new('ID', Constraint::exact(5))],
            $rows,
        );
        $area = new Rect(0, 0, 20, 4);

        for ($index = 0; $index < 100; ++$index) {
            $result = $table->update(new MouseMsg(5, 2, MouseAction::WheelDown, MouseButton::None), $area);
            $table = $result->model;
        }

        self::assertSame(2, $table->scrollOffset());
    }

    // --- Typed rows rendering with scrollOffset ---

    #[Test]
    public function typedRowsRenderWithScrollOffset(): void
    {
        $typedRows = [];
        for ($index = 0; $index < 10; ++$index) {
            $typedRows[] = Row::new("Row $index");
        }
        $table = Table\Table::new(
            [Column::new('Name', Constraint::flex())],
            $typedRows,
        )->withFocus(true);
        $area = Rect::fromSize(20, 4);

        for ($index = 0; $index < 5; ++$index) {
            $result = $table->update(new KeyMsg(Key::Down), $area);
            $table = $result->model;
        }

        $frame = new Frame(20, 4);
        $table->render($frame, $area, new RenderContext(Theme::dark()));

        $thirdRow = '';
        for ($column = 0; $column < 7; ++$column) {
            $thirdRow .= $frame->characters[1 * 20 + $column];
        }
        self::assertStringContainsString('Row', $thirdRow);
    }

    // --- Typed rows: header within scroll window ---

    #[Test]
    public function typedHeaderRowRendersWithinScrolledView(): void
    {
        $typedRows = [
            Row::new('A'),
            Row::new('B'),
            Row::new('C'),
            Row::header('Section'),
            Row::new('D'),
            Row::new('E'),
        ];
        $table = Table\Table::new(
            [Column::new('Name', Constraint::exact(10))],
            $typedRows,
        )->withFocus(true);
        $area = Rect::fromSize(20, 4);

        for ($index = 0; $index < 4; ++$index) {
            $result = $table->update(new KeyMsg(Key::Down), $area);
            $table = $result->model;
        }

        $frame = new Frame(20, 4);
        $table->render($frame, $area, new RenderContext(Theme::dark()));

        $theme = Theme::dark();
        $foundHeader = false;
        for ($row = 1; $row < 4; ++$row) {
            if ($frame->characters[$row * 20 + 0] === 'S') {
                $foundHeader = true;
                self::assertSame($theme->headerStyle->foregroundValue(), $frame->foregrounds[$row * 20]);
                break;
            }
        }
        self::assertTrue($foundHeader, 'Header row should be visible in scrolled view');
    }

    // --- Plain row rendering with selected row not at cursor ---

    #[Test]
    public function aSelectionAwayFromTheCursorIsMarkedApartFromIt(): void
    {
        $table = $this->createTable()->withFocus(true);
        $area = Rect::fromSize(60, 10);

        $result = $table->update(new KeyMsg(Key::Enter), $area);
        $result = $result->model->update(new KeyMsg(Key::Down), $area);
        $table = $result->model;

        self::assertSame(1, $table->cursor());
        self::assertSame(0, $table->selectedIndex());

        $frame = new Frame(60, 10);
        $theme = Theme::dark();
        $table->render($frame, $area, RenderContext::focused($theme));

        self::assertSame($theme->selectedStyle->backgroundValue(), $frame->backgrounds[1 * 60], 'the chosen row');
        self::assertSame($theme->cursorStyle->backgroundValue(), $frame->backgrounds[2 * 60], 'and the one under the cursor');
    }

    // --- Typed row: reverse applied to full totalWidth ---

    #[Test]
    public function theHighlightOfATypedRowFillsEntireTotalWidth(): void
    {
        $table = Table\Table::new(
            [Column::new('A', Constraint::exact(3)), Column::new('B', Constraint::exact(4))],
            [Row::new('abc', 'defg')],
        )->withFocus(true);
        $frame = new Frame(20, 3);
        $theme = Theme::dark();
        $table->render($frame, Rect::fromSize(20, 3), RenderContext::focused($theme));

        for ($column = 0; $column < 7; ++$column) {
            self::assertSame(
                $theme->cursorStyle->backgroundValue(),
                $frame->backgrounds[20 + $column],
                "Column $column should carry the highlight in a typed row"
            );
        }
    }

    // --- handlePageDown with typed rows: target decrements past non-selectable ---

    #[Test]
    public function handlePageDownSkipsTrailingNonSelectable(): void
    {
        $table = Table\Table::new(
            [Column::new('Name', Constraint::flex())],
            [
                Row::new('Alice'),
                Row::new('Bob'),
                Row::header('Divider'),
            ],
        )->withFocus(true);
        $area = Rect::fromSize(40, 3);

        $result = $table->update(new KeyMsg(Key::PageDown), $area);
        self::assertSame(1, $result->model->cursor());
    }

    // --- handlePageUp with typed rows: target increments past non-selectable ---

    #[Test]
    public function handlePageUpSkipsLeadingNonSelectable(): void
    {
        $table = Table\Table::new(
            [Column::new('Name', Constraint::flex())],
            [
                Row::header('Divider'),
                Row::new('Alice'),
                Row::new('Bob'),
            ],
        )->withFocus(true);
        $area = Rect::fromSize(40, 3);

        $result = $table->update(new KeyMsg(Key::End), $area);
        $result = $result->model->update(new KeyMsg(Key::PageUp), $area);
        self::assertSame(1, $result->model->cursor());
    }

    // --- L256: localY = message->y - area->y (Minus mutation) ---

    #[Test]
    public function mouseClickLocalYSubtractsAreaY(): void
    {
        $table = $this->createTable();
        $area = new Rect(0, 5, 60, 10);

        $click = new MouseMsg(5, 6, MouseAction::Press, MouseButton::Left);
        $result = $table->update($click, $area);
        self::assertSame(0, $result->model->cursor());
        self::assertSame(0, $result->model->selectedIndex());
    }

    // --- L257: rowIndex = localY - 1 + scrollOffset (Minus, Plus mutations) ---

    #[Test]
    public function mouseClickRowIndexAccountsForHeaderAndScroll(): void
    {
        $rows = array_map(fn (int $index) => [(string)$index], range(0, 9));
        $table = Table\Table::fromCells(
            [Column::new('ID', Constraint::exact(5))],
            $rows,
        );
        $area = new Rect(0, 0, 20, 5);

        $click = new MouseMsg(5, 1, MouseAction::Press, MouseButton::Left);
        $result = $table->update($click, $area);
        self::assertSame(0, $result->model->cursor());
        self::assertSame(0, $result->model->selectedIndex());
    }

    // --- L259: localY >= 1 (GreaterThanOrEqualTo mutation) ---

    #[Test]
    public function mouseClickAtLocalYZeroIsHeader(): void
    {
        $table = $this->createTable();
        $area = new Rect(0, 0, 60, 10);

        $click = new MouseMsg(5, 0, MouseAction::Press, MouseButton::Left);
        $result = $table->update($click, $area);
        self::assertSame($table, $result->model);
    }

    #[Test]
    public function mouseClickAtLocalYOneIsFirstRow(): void
    {
        $table = $this->createTable();
        $area = new Rect(0, 0, 60, 10);

        $click = new MouseMsg(5, 1, MouseAction::Press, MouseButton::Left);
        $result = $table->update($click, $area);
        self::assertSame(0, $result->model->cursor());
        self::assertSame(0, $result->model->selectedIndex());
    }

    // --- L259: rowIndex >= 0 check ---

    #[Test]
    public function mouseClickRowIndexCannotBeNegative(): void
    {
        $table = $this->createTable();
        $area = new Rect(0, 0, 60, 10);

        $click = new MouseMsg(5, 0, MouseAction::Press, MouseButton::Left);
        $result = $table->update($click, $area);
        self::assertSame($table, $result->model);
    }

    // --- L259: rowIndex < rowCount check ---

    #[Test]
    public function mouseClickRowIndexMustBeLessThanRowCount(): void
    {
        $table = Table\Table::fromCells(
            [Column::new('ID', Constraint::exact(5))],
            [['1'], ['2']],
        );
        $area = new Rect(0, 0, 60, 10);

        $click = new MouseMsg(5, 2, MouseAction::Press, MouseButton::Left);
        $result = $table->update($click, $area);
        self::assertSame(1, $result->model->cursor());
        self::assertSame(1, $result->model->selectedIndex());

        $click = new MouseMsg(5, 3, MouseAction::Press, MouseButton::Left);
        $result = $table->update($click, $area);
        self::assertSame($table, $result->model);
    }

    // --- L260: cursor and selected are both set ---

    #[Test]
    public function mouseClickSetsBothCursorAndSelected(): void
    {
        $table = $this->createTable();
        $area = new Rect(0, 0, 60, 10);

        $click = new MouseMsg(5, 3, MouseAction::Press, MouseButton::Left);
        $result = $table->update($click, $area);
        self::assertSame(2, $result->model->cursor());
        self::assertSame(2, $result->model->selectedIndex());
    }

    // --- L266: maxOffset = max(0, rowCount - (height - 1)) ---

    #[Test]
    public function wheelDownMaxOffsetWithFewRows(): void
    {
        $table = Table\Table::fromCells(
            [Column::new('ID', Constraint::exact(5))],
            [['1']],
        );
        $area = new Rect(0, 0, 20, 10);

        $wheelDown = new MouseMsg(5, 2, MouseAction::WheelDown, MouseButton::None);
        $result = $table->update($wheelDown, $area);
        self::assertSame(0, $result->model->scrollOffset());
    }

    // --- L268: scrollOffset + wheelSteps() (PlusEqual mutation) ---

    #[Test]
    public function wheelDownIncrementsByExactlyOne(): void
    {
        $rows = array_map(fn (int $index) => [(string)$index], range(0, 19));
        $table = Table\Table::fromCells(
            [Column::new('ID', Constraint::exact(5))],
            $rows,
        );
        $area = new Rect(0, 0, 20, 5);

        $result = $table->update(new MouseMsg(5, 2, MouseAction::WheelDown, MouseButton::None), $area);
        self::assertSame(1, $result->model->scrollOffset());

        $result = $result->model->update(new MouseMsg(5, 2, MouseAction::WheelDown, MouseButton::None), $area);
        self::assertSame(2, $result->model->scrollOffset());
    }

    // --- L272: scrollOffset - wheelSteps() (MinusEqual mutation) ---

    #[Test]
    public function wheelUpDecrementsByExactlyOne(): void
    {
        $rows = array_map(fn (int $index) => [(string)$index], range(0, 19));
        $table = Table\Table::fromCells(
            [Column::new('ID', Constraint::exact(5))],
            $rows,
        );
        $area = new Rect(0, 0, 20, 5);

        $result = $table->update(new MouseMsg(5, 2, MouseAction::WheelDown, MouseButton::None), $area);
        $result = $result->model->update(new MouseMsg(5, 2, MouseAction::WheelDown, MouseButton::None), $area);
        $result = $result->model->update(new MouseMsg(5, 2, MouseAction::WheelDown, MouseButton::None), $area);
        self::assertSame(3, $result->model->scrollOffset());

        $result = $result->model->update(new MouseMsg(5, 2, MouseAction::WheelUp, MouseButton::None), $area);
        self::assertSame(2, $result->model->scrollOffset());
    }

    // --- L293-295: exact column condition min === max && min > 0 ---

    #[Test]
    public function flexColumnWithMinEqualsMaxAndMinZeroIsFlex(): void
    {
        $table = Table\Table::fromCells(
            [Column::new('A', new Constraint(min: 0, max: 0)), Column::new('B', Constraint::flex())],
            [['AAAA', 'BBBB']],
        );
        $frame = new Frame(20, 3);
        $table->render($frame, Rect::fromSize(20, 3), new RenderContext(Theme::dark()));

        $dataRow = '';
        for ($column = 0; $column < 20; ++$column) {
            $dataRow .= $frame->characters[20 + $column];
        }
        self::assertStringContainsString('B', $dataRow);
    }

    // --- L301: totalFlex > 0 && remaining > 0 ---

    #[Test]
    public function allExactColumnsLeaveNoFlexDistribution(): void
    {
        $table = Table\Table::fromCells(
            [Column::new('A', Constraint::exact(5)), Column::new('B', Constraint::exact(5))],
            [['AAAAA', 'BBBBB']],
        );
        $frame = new Frame(30, 3);
        $table->render($frame, Rect::fromSize(30, 3), new RenderContext(Theme::dark()));

        self::assertSame('A', $frame->characters[30 + 0]);
        self::assertSame('B', $frame->characters[30 + 5]);
        self::assertSame(' ', $frame->characters[30 + 10]);
    }

    // --- L302: lastFlexIndex = -1 ---

    #[Test]
    public function lastFlexIndexWithSingleFlexColumn(): void
    {
        $table = Table\Table::fromCells(
            [Column::new('Only', Constraint::flex())],
            [['Data']],
        );
        $frame = new Frame(10, 3);
        $table->render($frame, Rect::fromSize(10, 3), new RenderContext(Theme::dark()));

        self::assertSame('D', $frame->characters[10 + 0]);
        self::assertSame('a', $frame->characters[10 + 1]);
        self::assertSame('t', $frame->characters[10 + 2]);
        self::assertSame('a', $frame->characters[10 + 3]);
    }

    // --- L309: floor(remaining * flex / totalFlex) ---

    #[Test]
    public function flexDistributionWithDifferentWeights(): void
    {
        $table = Table\Table::fromCells(
            [Column::new('A', Constraint::flex(3)), Column::new('B', Constraint::flex(1))],
            [['AAAAAAAAAAAAAAAA', 'BBBBBBBB']],
        );
        $frame = new Frame(20, 3);
        $table->render($frame, Rect::fromSize(20, 3), new RenderContext(Theme::dark()));

        $firstB = -1;
        for ($column = 0; $column < 20; ++$column) {
            if ($frame->characters[20 + $column] === 'B') {
                $firstB = $column;
                break;
            }
        }
        self::assertSame(15, $firstB);
    }

    // --- L316-317: remainder distribution: lastFlexIndex >= 0 && flexUsed < remaining ---

    #[Test]
    public function remainderGoesToLastFlexColumn(): void
    {
        $table = Table\Table::fromCells(
            [Column::new('A', Constraint::flex(1)), Column::new('B', Constraint::flex(1))],
            [['AAAAAAAAAA', 'BBBBBBBBBB']],
        );
        $frame = new Frame(11, 3);
        $table->render($frame, Rect::fromSize(11, 3), new RenderContext(Theme::dark()));

        $aCount = 0;
        $bCount = 0;
        for ($column = 0; $column < 11; ++$column) {
            if ($frame->characters[11 + $column] === 'A') {
                ++$aCount;
            }
            if ($frame->characters[11 + $column] === 'B') {
                ++$bCount;
            }
        }
        self::assertSame(5, $aCount);
        self::assertSame(6, $bCount);
    }

    // --- L327: headerStyle gets underline ---

    #[Test]
    public function headerHasUnderlineAttribute(): void
    {
        $table = Table\Table::fromCells(
            [Column::new('Test', Constraint::exact(5))],
            [['data']],
        );
        $frame = new Frame(20, 3);
        $table->render($frame, Rect::fromSize(20, 3), new RenderContext(Theme::dark()));

        self::assertSame(Attr::UNDERLINE, $frame->attributes[0] & Attr::UNDERLINE);
    }

    // --- L331: columnWidths fallback via ?? 0 ---

    #[Test]
    public function columnWidthsFallbackToZero(): void
    {
        $table = Table\Table::fromCells(
            [Column::new('A', Constraint::exact(5))],
            [['data']],
        );
        $frame = new Frame(20, 3);
        $table->render($frame, Rect::fromSize(20, 3), new RenderContext(Theme::dark()));
        self::assertSame('d', $frame->characters[20 + 0]);
    }

    // --- L345: visibleRows in renderRows = area->height - 1 ---

    #[Test]
    public function renderRowsVisibleRowsEqualsHeightMinusOne(): void
    {
        $table = Table\Table::fromCells(
            [Column::new('ID', Constraint::exact(3))],
            [['A'], ['B'], ['C'], ['D'], ['E']],
        );
        $frame = new Frame(20, 4);
        $table->render($frame, Rect::fromSize(20, 4), new RenderContext(Theme::dark()));

        self::assertSame('A', $frame->characters[1 * 20 + 0]);
        self::assertSame('B', $frame->characters[2 * 20 + 0]);
        self::assertSame('C', $frame->characters[3 * 20 + 0]);
    }

    // --- L347: useTypedRows = typedRows !== [] ---

    #[Test]
    public function useTypedRowsBranch(): void
    {
        $table = Table\Table::new(
            [Column::new('Name', Constraint::exact(10))],
            [Row::new('Alice'), Row::new('Bob')],
        );
        $frame = new Frame(20, 4);
        $table->render($frame, Rect::fromSize(20, 4), new RenderContext(Theme::dark()));

        self::assertSame('A', $frame->characters[1 * 20 + 0]);
        self::assertSame('B', $frame->characters[2 * 20 + 0]);
    }

    // --- L348: totalWidth = array_sum(columnWidths) ---

    #[Test]
    public function totalWidthIsArraySumOfColumnWidths(): void
    {
        $table = Table\Table::fromCells(
            [Column::new('A', Constraint::exact(5)), Column::new('B', Constraint::exact(7))],
            [['AAAAA', 'BBBBBBB']],
        )->withFocus(true);
        $frame = new Frame(30, 3);
        $theme = Theme::dark();
        $table->render($frame, Rect::fromSize(30, 3), RenderContext::focused($theme));

        $band = $theme->cursorStyle->backgroundValue();
        self::assertSame($band, $frame->backgrounds[30 + 0]);
        self::assertSame($band, $frame->backgrounds[30 + 11], 'the highlight runs to the last column');
        self::assertNotSame($band, $frame->backgrounds[30 + 12], 'and stops there');
    }

    // --- L350-353: visibleIndex loop and break ---

    #[Test]
    public function visibleIndexLoopBreaksAtRowCount(): void
    {
        $table = Table\Table::fromCells(
            [Column::new('ID', Constraint::exact(3))],
            [['A']],
        );
        $frame = new Frame(20, 10);
        $table->render($frame, Rect::fromSize(20, 10), new RenderContext(Theme::dark()));

        self::assertSame('A', $frame->characters[1 * 20 + 0]);
        for ($row = 2; $row < 10; ++$row) {
            self::assertSame(' ', $frame->characters[$row * 20 + 0]);
        }
    }

    // --- L356: renderY = area->y + 1 + visibleIndex ---

    #[Test]
    public function renderYCalculation(): void
    {
        $table = Table\Table::fromCells(
            [Column::new('ID', Constraint::exact(3))],
            [['X'], ['Y'], ['Z']],
        );
        $frame = new Frame(20, 10);
        $table->render($frame, new Rect(0, 2, 10, 5), new RenderContext(Theme::dark()));

        self::assertSame('I', $frame->characters[2 * 20 + 0]);
        self::assertSame('X', $frame->characters[3 * 20 + 0]);
        self::assertSame('Y', $frame->characters[4 * 20 + 0]);
        self::assertSame('Z', $frame->characters[5 * 20 + 0]);
    }

    // --- L374-376: isCursor, isSelected, reverse conditions ---

    #[Test]
    public function cursorWithoutFocusDoesNotReverse(): void
    {
        $table = $this->createTable();
        $frame = new Frame(60, 10);
        $table->render($frame, Rect::fromSize(60, 10), new RenderContext(Theme::dark()));

        self::assertSame(0, $frame->attributes[60] & Attr::REVERSE);
    }

    // --- L404: non-selectable typed row returns early ---

    #[Test]
    public function nonSelectableTypedRowReturnsEarly(): void
    {
        $theme = Theme::dark();
        $table = Table\Table::new(
            [Column::new('A', Constraint::exact(5)), Column::new('B', Constraint::exact(5))],
            [Row::header('Group Label')],
        );
        $frame = new Frame(20, 3);
        $table->render($frame, Rect::fromSize(20, 3), new RenderContext($theme));

        self::assertSame('G', $frame->characters[20 + 0]);
        self::assertSame('r', $frame->characters[20 + 1]);
        self::assertSame($theme->headerStyle->foregroundValue(), $frame->foregrounds[20 + 0]);
    }

    // --- L406: header row truncated to totalWidth, not individual column width ---

    #[Test]
    public function headerRowTruncatesToTotalWidthNotColumnWidth(): void
    {
        $table = Table\Table::new(
            [Column::new('A', Constraint::exact(3)), Column::new('B', Constraint::exact(4))],
            [Row::header('ABCDEFGHIJK')],
        );
        $frame = new Frame(20, 3);
        $table->render($frame, Rect::fromSize(20, 3), new RenderContext(Theme::dark()));

        $headerContent = '';
        for ($column = 0; $column < 10; ++$column) {
            $headerContent .= $frame->characters[20 + $column];
        }
        self::assertSame('ABCDEFG   ', $headerContent);
    }

    // --- L425: rowStyle fallback for typed row without custom style ---

    #[Test]
    public function typedRowWithoutCustomStyleUsesDefault(): void
    {
        $theme = Theme::dark();
        $table = Table\Table::new(
            [Column::new('Name', Constraint::flex())],
            [Row::new('Alice'), Row::new('Bob')],
        );
        $frame = new Frame(40, 4);
        $table->render($frame, Rect::fromSize(40, 4), new RenderContext($theme));

        self::assertSame($theme->dim->foregroundValue(), $frame->foregrounds[2 * 40]);
    }

    #[Test]
    public function spanWithoutStyleOnNonReversedRowUsesRowStyle(): void
    {
        $rowStyle = new Style(foreground: Color::ansi(6));
        $table = Table\Table::new(
            [Column::new('Name', Constraint::flex())],
            [Row::new('First'), Row::new(new Span('Data'))->withStyle($rowStyle)],
        )->withFocus(true);
        $frame = new Frame(40, 4);
        $table->render($frame, Rect::fromSize(40, 4), new RenderContext(Theme::dark()));

        self::assertSame($rowStyle->foregroundValue(), $frame->foregrounds[2 * 40]);
    }

    // --- L454-472: ensureCursorVisible edge cases ---

    #[Test]
    public function ensureCursorVisibleWithHeightOfOne(): void
    {
        $rows = array_map(fn (int $index) => [(string)$index], range(0, 5));
        $table = Table\Table::fromCells(
            [Column::new('ID', Constraint::exact(3))],
            $rows,
        )->withFocus(true);
        $area = Rect::fromSize(20, 2);

        $result = $table->update(new KeyMsg(Key::Down), $area);
        self::assertSame(1, $result->model->cursor());
        self::assertSame(1, $result->model->scrollOffset());
    }

    #[Test]
    public function ensureCursorVisibleScrollsUpExactly(): void
    {
        $rows = array_map(fn (int $index) => [(string)$index], range(0, 9));
        $table = Table\Table::fromCells(
            [Column::new('ID', Constraint::exact(3))],
            $rows,
        )->withFocus(true);
        $area = Rect::fromSize(20, 4);

        $result = $table->update(new KeyMsg(Key::End), $area);
        $table = $result->model;
        self::assertSame(9, $table->cursor());
        self::assertSame(7, $table->scrollOffset());

        $result = $table->update(new KeyMsg(Key::Home), $area);
        $table = $result->model;
        self::assertSame(0, $table->cursor());
        self::assertSame(0, $table->scrollOffset());
    }

    // --- L459-461: cursor < offset adjustment ---

    #[Test]
    public function ensureCursorVisibleWhenCursorAboveOffset(): void
    {
        $rows = array_map(fn (int $index) => [(string)$index], range(0, 19));
        $table = Table\Table::fromCells(
            [Column::new('ID', Constraint::exact(3))],
            $rows,
        )->withFocus(true);
        $area = Rect::fromSize(20, 6);

        for ($index = 0; $index < 15; ++$index) {
            $result = $table->update(new KeyMsg(Key::Down), $area);
            $table = $result->model;
        }
        self::assertSame(15, $table->cursor());
        self::assertGreaterThan(0, $table->scrollOffset());

        $result = $table->update(new KeyMsg(Key::Home), $area);
        $table = $result->model;
        self::assertSame(0, $table->cursor());
        self::assertSame(0, $table->scrollOffset());
    }

    // --- L463-465: cursor >= offset + visibleRows adjustment ---

    #[Test]
    public function ensureCursorVisibleWhenCursorBelowViewport(): void
    {
        $rows = array_map(fn (int $index) => [(string)$index], range(0, 9));
        $table = Table\Table::fromCells(
            [Column::new('ID', Constraint::exact(3))],
            $rows,
        )->withFocus(true);
        $area = Rect::fromSize(20, 3);

        $result = $table->update(new KeyMsg(Key::End), $area);
        self::assertSame(9, $result->model->cursor());
        self::assertSame(8, $result->model->scrollOffset());
    }

    // --- L467-468: offset !== scrollOffset check ---

    #[Test]
    public function ensureCursorVisibleReturnsSelfWhenNoChangeNeeded(): void
    {
        $table = Table\Table::fromCells(
            [Column::new('ID', Constraint::exact(3))],
            [['A'], ['B'], ['C']],
        )->withFocus(true);
        $area = Rect::fromSize(20, 10);

        $result = $table->update(new KeyMsg(Key::Down), $area);
        self::assertSame(1, $result->model->cursor());
        self::assertSame(0, $result->model->scrollOffset());
    }

    // --- L209-210: Down boundary check (cursor < rowCount - 1) ---

    #[Test]
    public function downAtLastRowReturnsSelfPlainRows(): void
    {
        $table = Table\Table::fromCells(
            [Column::new('ID', Constraint::exact(3))],
            [['A'], ['B']],
        )->withFocus(true);
        $area = Rect::fromSize(20, 10);

        $result = $table->update(new KeyMsg(Key::Down), $area);
        self::assertSame(1, $result->model->cursor());

        $endTable = $result->model;
        $result = $endTable->update(new KeyMsg(Key::Down), $area);
        self::assertSame($endTable, $result->model);
    }

    // --- L211: Home when cursor > 0 ---

    #[Test]
    public function homeMovesCursorToZeroFromMiddle(): void
    {
        $table = Table\Table::fromCells(
            [Column::new('ID', Constraint::exact(3))],
            [['A'], ['B'], ['C']],
        )->withFocus(true);
        $area = Rect::fromSize(20, 10);

        $result = $table->update(new KeyMsg(Key::Down), $area);
        $result = $result->model->update(new KeyMsg(Key::Home), $area);
        self::assertSame(0, $result->model->cursor());
    }

    // --- L212: End when cursor < rowCount - 1 ---

    #[Test]
    public function endMovesCursorToRowCountMinusOne(): void
    {
        $table = Table\Table::fromCells(
            [Column::new('ID', Constraint::exact(3))],
            [['A'], ['B'], ['C'], ['D'], ['E']],
        )->withFocus(true);
        $area = Rect::fromSize(20, 10);

        $result = $table->update(new KeyMsg(Key::End), $area);
        self::assertSame(4, $result->model->cursor());
    }

    // --- L213-214: PageDown clamped with min ---

    #[Test]
    public function pageDownClampsWithMin(): void
    {
        $table = Table\Table::fromCells(
            [Column::new('ID', Constraint::exact(3))],
            [['A'], ['B'], ['C'], ['D'], ['E']],
        )->withFocus(true);
        $area = Rect::fromSize(20, 100);

        $result = $table->update(new KeyMsg(Key::PageDown), $area);
        self::assertSame(4, $result->model->cursor());
    }

    // --- L216-217: PageUp clamped with max ---

    #[Test]
    public function pageUpClampsWithMax(): void
    {
        $table = Table\Table::fromCells(
            [Column::new('ID', Constraint::exact(3))],
            [['A'], ['B'], ['C'], ['D'], ['E']],
        )->withFocus(true);
        $area = Rect::fromSize(20, 100);

        $result = $table->update(new KeyMsg(Key::End), $area);
        $result = $result->model->update(new KeyMsg(Key::PageUp), $area);
        self::assertSame(0, $result->model->cursor());
    }

    // --- L155: rowCount returns count(typedRows) when not empty ---

    #[Test]
    public function rowCountReturnsTypedRowsCountWhenNonEmpty(): void
    {
        $table = Table\Table::new(
            [Column::new('Name', Constraint::flex())],
            [Row::new('A')],
        )->withFocus(true);
        $area = Rect::fromSize(40, 10);

        $result = $table->update(new KeyMsg(Key::Down), $area);
        self::assertSame($table, $result->model);
    }

    // --- L155: rowCount returns count(rows) when typedRows empty ---

    #[Test]
    public function rowCountReturnsPlainRowsCount(): void
    {
        $table = Table\Table::fromCells(
            [Column::new('ID', Constraint::exact(3))],
            [['A'], ['B'], ['C']],
        )->withFocus(true);
        $area = Rect::fromSize(20, 10);

        $result = $table->update(new KeyMsg(Key::End), $area);
        self::assertSame(2, $result->model->cursor());
    }

    // --- L160-164: isRowSelectable with empty typedRows ---

    #[Test]
    public function allPlainRowsAreSelectable(): void
    {
        $table = $this->createTable()->withFocus(true);
        $area = Rect::fromSize(60, 10);

        for ($index = 0; $index < 3; ++$index) {
            if ($index > 0) {
                $result = $table->update(new KeyMsg(Key::Down), $area);
                $table = $result->model;
            }
            $result = $table->update(new KeyMsg(Key::Enter), $area);
            self::assertSame($index, $result->model->selectedIndex());
            $table = $result->model->update(new KeyMsg(Key::Enter), $area)->model;
        }
    }

    // --- L164: null-safe property call (?->selectable) ---

    #[Test]
    public function isRowSelectableWithOutOfBoundsTypedRowDefaultsTrue(): void
    {
        $table = Table\Table::new(
            [Column::new('Name', Constraint::flex())],
            [Row::new('Alice')],
        );
        $area = new Rect(0, 0, 40, 5);

        $click = new MouseMsg(5, 1, MouseAction::Press, MouseButton::Left);
        $result = $table->update($click, $area);
        self::assertSame(0, $result->model->cursor());
    }

    // --- L170: position = from + direction ---

    #[Test]
    public function findNextSelectableDirectionTraversal(): void
    {
        $table = Table\Table::new(
            [Column::new('Name', Constraint::flex())],
            [Row::new('A'), Row::header('H'), Row::new('B')],
        )->withFocus(true);
        $area = Rect::fromSize(40, 10);

        $result = $table->update(new KeyMsg(Key::Down), $area);
        self::assertSame(2, $result->model->cursor());

        $result = $result->model->update(new KeyMsg(Key::Up), $area);
        self::assertSame(0, $result->model->cursor());
    }

    // --- L172: while position >= 0 && position < rowCount ---

    #[Test]
    public function findNextSelectableStopsAtBounds(): void
    {
        $table = Table\Table::new(
            [Column::new('Name', Constraint::flex())],
            [Row::header('H1'), Row::new('A'), Row::header('H2')],
        )->withFocus(true);
        $area = Rect::fromSize(40, 10);

        $result = $table->update(new KeyMsg(Key::Up), $area);
        self::assertSame(1, $result->model->cursor());

        $result = $table->update(new KeyMsg(Key::Down), $area);
        self::assertSame(1, $result->model->cursor());
    }

    // --- Typed rows: PageDown exact target computation ---

    #[Test]
    public function typedRowsPageDownExactTarget(): void
    {
        $rows = [];
        for ($index = 0; $index < 10; ++$index) {
            $rows[] = Row::new("Item $index");
        }
        $table = Table\Table::new(
            [Column::new('Name', Constraint::flex())],
            $rows,
        )->withFocus(true);
        $area = Rect::fromSize(40, 4);

        $result = $table->update(new KeyMsg(Key::PageDown), $area);
        self::assertSame(3, $result->model->cursor());
    }

    // --- Typed rows: PageUp exact target computation ---

    #[Test]
    public function typedRowsPageUpExactTarget(): void
    {
        $rows = [];
        for ($index = 0; $index < 10; ++$index) {
            $rows[] = Row::new("Item $index");
        }
        $table = Table\Table::new(
            [Column::new('Name', Constraint::flex())],
            $rows,
        )->withFocus(true);
        $area = Rect::fromSize(40, 4);

        $result = $table->update(new KeyMsg(Key::End), $area);
        self::assertSame(9, $result->model->cursor());

        $result = $result->model->update(new KeyMsg(Key::PageUp), $area);
        self::assertSame(6, $result->model->cursor());
    }

    #[Test]
    public function cellTruncationUsesDisplayWidth(): void
    {
        $table = Table\Table::fromCells(
            [Column::new('Name', Constraint::exact(5))],
            [["\u{4F60}\u{597D}\u{4E16}\u{754C}"]],
        );

        $frame = new Frame(10, 3);
        $table->render($frame, Rect::fromSize(10, 3), new RenderContext(Theme::dark()));

        // "你好世界" = 8 display cols. Column width = 5.
        // Should truncate to "你好" (4 cols) + 1 empty. Column 5 should be empty.
        $cellAt5 = $frame->characters[1 * 10 + 5];
        self::assertSame(' ', $cellAt5);
    }

    public static function tableKeybindingOverrideProvider(): iterable
    {
        yield Actions::CURSOR_UP => [Actions::CURSOR_UP, new KeyMsg(Key::Up),       new KeyMsg('a'), 2, fn (Table\Table $t) => $t->cursor() === 1];
        yield Actions::CURSOR_DOWN => [Actions::CURSOR_DOWN, new KeyMsg(Key::Down),     new KeyMsg('b'), 0, fn (Table\Table $t) => $t->cursor() === 1];
        yield Actions::HOME => [Actions::HOME,       new KeyMsg(Key::Home),     new KeyMsg('c'), 2, fn (Table\Table $t) => $t->cursor() === 0];
        yield Actions::END => [Actions::END,        new KeyMsg(Key::End),      new KeyMsg('d'), 0, fn (Table\Table $t) => $t->cursor() === 2];
        yield Actions::PAGE_UP => [Actions::PAGE_UP,    new KeyMsg(Key::PageUp),   new KeyMsg('e'), 2, fn (Table\Table $t) => $t->cursor() < 2];
        yield Actions::PAGE_DOWN => [Actions::PAGE_DOWN,  new KeyMsg(Key::PageDown), new KeyMsg('f'), 0, fn (Table\Table $t) => $t->cursor() > 0];
        yield Actions::SELECT => [Actions::SELECT,     new KeyMsg(Key::Enter),    new KeyMsg('g'), 0, fn (Table\Table $t) => $t->selectedIndex() === 0];
    }

    #[DataProvider('tableKeybindingOverrideProvider')]
    #[Test]
    public function supportsCustomKeybinding(
        string $action,
        KeyMsg $defaultKey,
        KeyMsg $customKey,
        int $startingCursor,
        Closure $assertExpected,
    ): void {
        $area = Rect::fromSize(40, 10);
        $widget = $this->createTable()->withFocus(true)->withCursor($startingCursor);

        $afterDefault = $widget->update($defaultKey, $area)->model;
        self::assertNotSame($widget, $afterDefault);
        self::assertTrue($assertExpected($afterDefault));

        $customKeyPattern = is_string($customKey->key) ? $customKey->key : 'x';
        $custom = $widget->withKeybindings(new Keybindings([$action => [$customKeyPattern]]));
        self::assertSame($custom, $custom->update($defaultKey, $area)->model);

        $afterCustom = $custom->update($customKey, $area)->model;
        self::assertNotSame($custom, $afterCustom);
        self::assertTrue($assertExpected($afterCustom));
    }

    #[Test]
    public function aKeyThatCannotMoveTheCursorChangesNothingAtAll(): void
    {
        $area = new Rect(0, 0, 20, 4);
        $rows = [];
        for ($index = 0; $index < 10; ++$index) {
            $rows[] = ["row{$index}"];
        }
        $columns = [Column::new('A', Constraint::flex())];

        // The offset is set apart from the cursor on purpose: a key that moves
        // nothing must not scroll either.
        $atBottom = Table\Table::fromCells($columns, $rows)->withFocus(true)->withCursor(9)->withScrollOffset(0);
        foreach ([Key::End, Key::PageDown, Key::Down] as $key) {
            $result = $atBottom->update(new KeyMsg($key), $area)->model;
            self::assertInstanceOf(Table\Table::class, $result);
            self::assertSame(9, $result->cursor(), "{$key->name} on the last row");
            self::assertSame(0, $result->scrollOffset(), "{$key->name} left the offset alone");
        }

        $atTop = Table\Table::fromCells($columns, $rows)->withFocus(true)->withCursor(0)->withScrollOffset(5);
        foreach ([Key::Home, Key::PageUp, Key::Up] as $key) {
            $result = $atTop->update(new KeyMsg($key), $area)->model;
            self::assertInstanceOf(Table\Table::class, $result);
            self::assertSame(0, $result->cursor(), "{$key->name} on the first row");
            self::assertSame(5, $result->scrollOffset(), "{$key->name} left the offset alone");
        }
    }

    #[Test]
    public function movingTheCursorBelowTheViewportScrollsExactlyEnough(): void
    {
        // A four-row area leaves three rows below the header.
        $area = new Rect(0, 0, 20, 4);
        $rows = [];
        for ($index = 0; $index < 10; ++$index) {
            $rows[] = ["row{$index}"];
        }
        $table = Table\Table::fromCells([Column::new('A', Constraint::flex())], $rows)->withFocus(true);

        self::assertSame(0, $table->scrollOffset());

        for ($press = 0; $press < 5; ++$press) {
            $result = $table->update(new KeyMsg(Key::Down), $area);
            self::assertInstanceOf(Table\Table::class, $result->model);
            $table = $result->model;
        }

        self::assertSame(5, $table->cursor());
        self::assertSame(3, $table->scrollOffset(), 'Three rows fit, so rows 3 to 5 are shown');
    }

    #[Test]
    public function homeAndEndFindTheLoneSelectableRowFromANonSelectableCursor(): void
    {
        $area = new Rect(0, 0, 20, 6);
        $columns = [Column::new('A', Constraint::flex())];

        // Only the very first row can be selected, and the cursor starts past it.
        $onlyFirst = Table\Table::new($columns, [Row::new('first'), Row::header('h1'), Row::header('h2')])
            ->withFocus(true)->withCursor(2);
        $home = $onlyFirst->update(new KeyMsg(Key::Home), $area)->model;
        self::assertInstanceOf(Table\Table::class, $home);
        self::assertSame(0, $home->cursor(), 'Home has to look at row 0 itself');

        // And the mirror image: only the very last row can be selected.
        $onlyLast = Table\Table::new($columns, [Row::header('h1'), Row::header('h2'), Row::new('last')])
            ->withFocus(true)->withCursor(0);
        $end = $onlyLast->update(new KeyMsg(Key::End), $area)->model;
        self::assertInstanceOf(Table\Table::class, $end);
        self::assertSame(2, $end->cursor(), 'End has to look at the last row itself');
    }

    #[Test]
    public function selectingTheCursorRowTwiceKeepsItSelected(): void
    {
        $area = new Rect(0, 0, 20, 6);
        $table = Table\Table::fromCells(
            [Column::new('A', Constraint::flex())],
            [['one'], ['two'], ['three']],
        )->withFocus(true)->withCursor(1);

        self::assertSame(-1, $table->selectedIndex(), 'Nothing is selected to begin with');

        $selected = $table->update(new KeyMsg(Key::Enter), $area)->model;
        self::assertInstanceOf(Table\Table::class, $selected);
        self::assertSame(1, $selected->cursor());
        self::assertSame(1, $selected->selectedIndex());

        $again = $selected->update(new KeyMsg(Key::Enter), $area)->model;
        self::assertInstanceOf(Table\Table::class, $again);
        self::assertSame(1, $again->selectedIndex(), 'choosing the chosen row does not unchoose it');
    }

    #[Test]
    public function theScrollbarAppearsOnTheRowThatFirstDoesNotFit(): void
    {
        $columns = [Column::new('A', Constraint::flex())];
        $area = new Rect(0, 0, 10, 4);
        $click = new MouseMsg(0, 2, MouseAction::Press, MouseButton::Left);

        // Four rows in a four-row area leaves three lines for rows, so three fit
        // and four do not. Once a scrollbar is reserved the cell wraps onto two
        // lines, which moves what sits under a click on the second content line.
        $fits = $this->typedRowsOf(3);
        $clicked = Table\Table::new($columns, $fits)->withFocus(true)->update($click, $area)->model;
        self::assertInstanceOf(Table\Table::class, $clicked);
        self::assertSame(1, $clicked->cursor(), 'No scrollbar, so no wrap: the second line is row 1');

        $overflows = $this->typedRowsOf(4);
        $wrapped = Table\Table::new($columns, $overflows)->withFocus(true)->update($click, $area)->model;
        self::assertInstanceOf(Table\Table::class, $wrapped);
        self::assertSame(0, $wrapped->cursor(), 'One row more reserves the column, so row 0 spans both lines');
    }

    #[Test]
    public function aClickAccountsForTheColumnTheScrollbarTakesWhenCellsWrap(): void
    {
        $columns = [Column::new('A', Constraint::flex())];
        $area = new Rect(0, 0, 10, 6);
        $click = new MouseMsg(0, 2, MouseAction::Press, MouseButton::Left);

        // Three rows need no scrollbar, so the cell has all ten columns and fits
        // on one line: the second content line is row 1.
        $short = Table\Table::new($columns, [Row::new('abcdefghij'), Row::new('abcdefghij'), Row::new('abcdefghij')])
            ->withFocus(true)->update($click, $area)->model;
        self::assertInstanceOf(Table\Table::class, $short);
        self::assertSame(1, $short->cursor());

        // Eight rows need one, leaving nine columns, so each cell wraps onto two
        // lines and the same click is still inside row 0.
        $rows = [];
        for ($index = 0; $index < 8; ++$index) {
            $rows[] = Row::new('abcdefghij');
        }
        $tall = Table\Table::new($columns, $rows)->withFocus(true)->update($click, $area)->model;
        self::assertInstanceOf(Table\Table::class, $tall);
        self::assertSame(0, $tall->cursor());
    }

    #[Test]
    public function aClickMovesTheCursorToTheRowUnderThePointer(): void
    {
        $columns = [Column::new('A', Constraint::flex())];
        $table = Table\Table::fromCells($columns, [['one'], ['two'], ['three']])->withFocus(true);
        $area = new Rect(0, 0, 10, 6);

        // Row 0 sits on the line below the header, so y=2 is row 1.
        $clicked = $table->update(new MouseMsg(0, 2, MouseAction::Press, MouseButton::Left), $area)->model;
        self::assertInstanceOf(Table\Table::class, $clicked);
        self::assertSame(1, $clicked->cursor());

        $header = $table->update(new MouseMsg(0, 0, MouseAction::Press, MouseButton::Left), $area)->model;
        self::assertInstanceOf(Table\Table::class, $header);
        self::assertSame(0, $header->cursor(), 'A click on the header row moves nothing');
    }

    #[Test]
    public function pagingSkipsBackToASelectableRowWhenItLandsOnAHeader(): void
    {
        $area = new Rect(0, 0, 20, 6);
        $table = Table\Table::new(
            [Column::new('A', Constraint::flex())],
            [
                Row::new('r0'), Row::new('r1'), Row::new('r2'), Row::new('r3'),
                Row::new('r4'), Row::header('mid'), Row::new('r6'),
            ],
        )->withFocus(true);

        // A page of five from row 0 lands on the header at index 5, so it walks back.
        $down = $table->update(new KeyMsg(Key::PageDown), $area)->model;
        self::assertInstanceOf(Table\Table::class, $down);

        self::assertSame(4, $down->cursor());
    }

    #[Test]
    public function pagingMovesByAViewportAndStopsAtTheEnds(): void
    {
        // A six-row area leaves five rows below the header, so a page is five.
        $area = new Rect(0, 0, 20, 6);
        $rows = [];
        for ($index = 0; $index < 10; ++$index) {
            $rows[] = ["row{$index}"];
        }
        $table = Table\Table::fromCells([Column::new('A', Constraint::flex())], $rows)->withFocus(true);

        $down = $table->update(new KeyMsg(Key::PageDown), $area)->model;
        self::assertInstanceOf(Table\Table::class, $down);
        self::assertSame(5, $down->cursor());

        $twice = $down->update(new KeyMsg(Key::PageDown), $area)->model;
        self::assertInstanceOf(Table\Table::class, $twice);
        self::assertSame(9, $twice->cursor(), 'The last row, not past it');

        $up = $twice->update(new KeyMsg(Key::PageUp), $area)->model;
        self::assertInstanceOf(Table\Table::class, $up);
        self::assertSame(4, $up->cursor());

        $top = $up->update(new KeyMsg(Key::PageUp), $area)->model;
        self::assertInstanceOf(Table\Table::class, $top);
        self::assertSame(0, $top->cursor(), 'The first row, not before it');
    }

    #[Test]
    public function theCursorRowIsHighlightedOnlyWhileFocusedButASelectedRowAlways(): void
    {
        $table = Table\Table::fromCells(
            [Column::new('A', Constraint::flex())],
            [['one'], ['two'], ['three']],
        )->withCursor(1);

        self::assertTrue($this->rowIsReversed($table->withFocus(true), 1), 'The cursor row while focused');
        self::assertFalse($this->rowIsReversed($table, 1), 'The cursor row of an unfocused table is left plain');
        self::assertTrue($this->rowIsReversed($table->withSelected(1), 1), 'A selected row stays marked when focus goes');
        self::assertFalse($this->rowIsReversed($table->withFocus(true), 2), 'A row that is neither is left plain');
    }

    #[Test]
    public function aTypedCursorRowIsHighlightedOnTheSameTermsAsAPlainOne(): void
    {
        $table = Table\Table::new(
            [Column::new('A', Constraint::flex())],
            [Row::new('one'), Row::new('two'), Row::new('three')],
        )->withCursor(1);

        self::assertTrue($this->rowIsReversed($table->withFocus(true), 1), 'The cursor row while focused');
        self::assertFalse($this->rowIsReversed($table, 1), 'The cursor row of an unfocused table is left plain');
        self::assertTrue($this->rowIsReversed($table->withSelected(1), 1), 'A selected row stays marked when focus goes');
        self::assertFalse($this->rowIsReversed($table->withFocus(true), 2), 'A row that is neither is left plain');
    }

    #[Test]
    public function aNonSelectableTypedRowIsNeverHighlighted(): void
    {
        $table = Table\Table::new(
            [Column::new('A', Constraint::flex())],
            [Row::new('one'), Row::header('group'), Row::new('three')],
        )->withFocus(true)->withCursor(1)->withSelected(1);

        self::assertFalse($this->rowIsReversed($table, 1), 'A header row draws as a header whatever the cursor says');
    }

    #[Test]
    public function reloadingTypedRowsKeepsTheRowThatWasChosen(): void
    {
        $columns = [Column::new('A', Constraint::flex())];
        $table = Table\Table::new($columns, [Row::new('alpha'), Row::new('beta')])->withSelected(1);

        $reordered = $table->withRows([Row::new('beta'), Row::new('alpha')]);

        self::assertSame(
            ['beta'],
            $reordered->selectedRow(),
            'a choice is held by what was chosen, not by the place it sat in',
        );
    }

    #[Test]
    public function reloadingTypedRowsLeavesTheCursorOnARowAReaderCanChoose(): void
    {
        $columns = [Column::new('A', Constraint::flex())];
        $table = Table\Table::new($columns, [Row::new('alpha'), Row::new('beta')]);

        $regrouped = $table->withRows([Row::header('group'), Row::new('alpha'), Row::new('beta')]);

        self::assertSame(1, $regrouped->cursor(), 'a group label is not a row the cursor may rest on');
    }

    #[Test]
    public function switchingRowRepresentationDropsTheOtherOne(): void
    {
        $columns = [Column::new('A', Constraint::flex())];
        $area = new Rect(0, 0, 20, 6);

        $plain = Table\Table::new($columns, [Row::new('typedcell')])->withCells([['plaincell']]);
        $screen = $this->screenOfTable($plain, $area);
        self::assertStringContainsString('plaincell', $screen);
        self::assertStringNotContainsString('typedcell', $screen, 'The typed rows are gone');

        $typed = $plain->withRows([Row::new('typedcell')]);
        $screen = $this->screenOfTable($typed, $area);
        self::assertStringContainsString('typedcell', $screen);
        self::assertStringNotContainsString('plaincell', $screen, 'And the plain rows in turn');
    }

    #[Test]
    public function theColumnsCanBeReplacedAfterConstruction(): void
    {
        $area = new Rect(0, 0, 20, 6);
        $table = Table\Table::fromCells([Column::new('Before', Constraint::flex())], [['x']])
            ->withColumns([Column::new('After', Constraint::flex())]);

        $screen = $this->screenOfTable($table, $area);

        self::assertStringContainsString('After', $screen);
        self::assertStringNotContainsString('Before', $screen);
    }

    #[Test]
    public function anExplicitScrollOffsetChoosesTheFirstVisibleRow(): void
    {
        $area = new Rect(0, 0, 20, 5);
        $rows = [];
        for ($index = 0; $index < 10; ++$index) {
            $rows[] = ["row{$index}"];
        }
        $table = Table\Table::fromCells([Column::new('A', Constraint::flex())], $rows)->withScrollOffset(3);

        self::assertSame(3, $table->scrollOffset());

        $screen = $this->screenOfTable($table, $area);
        self::assertStringContainsString('row3', $screen, 'The offset row is the first one shown');
        self::assertStringNotContainsString('row2', $screen, 'And the one before it is not');
    }

    #[Test]
    public function aCursorMovedFromOutsideIsOnScreenAtTheNextRender(): void
    {
        $area = new Rect(0, 0, 20, 5);
        $rows = [];
        for ($index = 0; $index < 10; ++$index) {
            $rows[] = ["row{$index}"];
        }
        $table = Table\Table::fromCells([Column::new('A', Constraint::flex())], $rows)->withCursor(9);

        $screen = $this->screenOfTable($table, $area);

        self::assertStringContainsString('row9', $screen, 'The cursor row is shown although no key event moved it');
    }

    #[Test]
    public function aCursorMovedBackToTheFirstRowFromOutsideScrollsTheViewUp(): void
    {
        $area = new Rect(0, 0, 20, 5);
        $rows = [];
        for ($index = 0; $index < 10; ++$index) {
            $rows[] = ["row{$index}"];
        }
        $table = Table\Table::fromCells([Column::new('A', Constraint::flex())], $rows)->withFocus(true);
        $scrolled = $table->update(new KeyMsg(Key::End), $area)->model;
        self::assertSame(6, $scrolled->scrollOffset());

        $screen = $this->screenOfTable($scrolled->withCursor(0), $area);

        self::assertStringContainsString('row0', $screen, 'The view comes back up to the row the cursor was moved to');
    }

    #[Test]
    public function scrollingInsideARowTallerThanTheViewportRevealsItsLaterLines(): void
    {
        $long = implode(' ', array_map(static fn (int $index): string => "word{$index}", range(1, 40)));
        $table = Table\Table::fromCells(
            [Column::new('text', Constraint::flex())],
            [[$long], ['second']],
        )->withHeader(false);
        $area = new Rect(0, 0, 12, 4);

        $scrolled = $table->update(new MouseMsg(5, 2, MouseAction::WheelDown, MouseButton::None), $area)->model;

        self::assertInstanceOf(Table\Table::class, $scrolled);
        self::assertStringContainsString('word9', $this->screenOfTable($scrolled, $area), 'A cell taller than the viewport must not hide its later lines from every scroll position');
    }

    #[Test]
    public function anOffsetPastTheLastRowStillShowsTheEndOfTheTable(): void
    {
        $table = Table\Table::fromCells(
            [Column::new('name', Constraint::flex())],
            [['alpha'], ['bravo'], ['charlie'], ['delta'], ['echo']],
        )->withHeader(false);

        $screen = $this->screenOfTable($table->withScrollOffset(9999), new Rect(0, 0, 10, 4));

        self::assertStringContainsString('echo', $screen, 'An offset nobody can scroll back from must settle on the last rows');
    }

    protected function createFocusable(): Focusable
    {
        return $this->createTable();
    }

    /**
     * @return list<Row>
     */
    private function typedRowsOf(int $count): array
    {
        $rows = [];
        for ($index = 0; $index < $count; ++$index) {
            $rows[] = Row::new('abcdefghij');
        }

        return $rows;
    }

    private function screenOfTable(Table\Table $table, Rect $area): string
    {
        $frame = new Frame($area->width, $area->height);
        $table->render($frame, $area, new RenderContext(Theme::dark()));

        $screen = '';
        for ($cell = 0; $cell < $area->width * $area->height; ++$cell) {
            $screen .= $frame->characters[$cell];
        }

        return $screen;
    }

    private function rowIsReversed(Table\Table $table, int $rowIndex): bool
    {
        $theme = Theme::dark();
        $frame = new Frame(20, 6);
        $context = $table->isFocused() ? RenderContext::focused($theme) : RenderContext::unfocused($theme);
        $table->render($frame, new Rect(0, 0, 20, 6), $context);

        // One header row sits above the first row.
        $background = $frame->backgrounds[(1 + $rowIndex) * 20];

        return $background === $theme->cursorStyle->backgroundValue()
            || $background === $theme->selectedStyle->backgroundValue();
    }

    private function firstRowLine(Table\Table $table, int $width, int $height): string
    {
        $frame = new Frame($width, $height);
        $table->render($frame, new Rect(0, 0, $width, $height), new RenderContext(Theme::dark()));

        $line = '';
        for ($column = 0; $column < $width; ++$column) {
            $line .= $frame->characters[$width + $column];
        }

        return $line;
    }

    private function createTable(): Table\Table
    {
        return Table\Table::fromCells(
            [
                Column::new('ID', Constraint::exact(5)),
                Column::new('Name', Constraint::flex(2)),
                Column::new('Status', Constraint::flex(1)),
            ],
            [
                ['1', 'Alice', 'Active'],
                ['2', 'Bob', 'Inactive'],
                ['3', 'Charlie', 'Active'],
            ],
        );
    }

    private function rowText(Frame $frame, int $frameWidth, int $row, int $from, int $length): string
    {
        return rtrim(implode('', array_slice($frame->characters, $row * $frameWidth + $from, $length)));
    }
}
