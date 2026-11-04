<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Input\Presets\VimMode;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Rendering\Attr;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Span;
use Elmphant\Tui\Rendering\StyleToken;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Search\Matcher;
use Elmphant\Tui\Testing\ScreenBuffer;
use Elmphant\Tui\Testing\WidgetHarness;
use Elmphant\Tui\Widget\Filter\FilterableTable;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Structure\Panel;
use Elmphant\Tui\Widget\Table\Column;
use Elmphant\Tui\Widget\Table\Row;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FilterableTableTest extends TestCase
{
    #[Test]
    public function typingNarrowsTheRowsAndTheCursorNamesTheRowHandedIn(): void
    {
        $table = $this->harness()->type('coffee')->widget();

        self::assertInstanceOf(FilterableTable::class, $table);
        self::assertSame(1, $table->cursorSourceIndex());
        self::assertSame('1 of 3', $table->summary());
    }

    #[Test]
    public function theArrowsMoveTheTableCursor(): void
    {
        $table = $this->harness()->press('down', 'down')->widget();

        self::assertInstanceOf(FilterableTable::class, $table);
        self::assertSame(2, $table->cursorSourceIndex());
        self::assertSame('Train', $table->cursorRow()?->cells[1]);
    }

    #[Test]
    public function enterChoosesTheCursorRow(): void
    {
        $table = $this->harness()->type('train')->widget();

        self::assertInstanceOf(FilterableTable::class, $table);
        self::assertTrue($table->commits(new KeyMsg('enter')));
        self::assertNull($table->selectedRow());

        $chosen = WidgetHarness::for($table, 40, 8)->press('enter')->widget();

        self::assertInstanceOf(FilterableTable::class, $chosen);
        self::assertSame('Train', $chosen->selectedRow()?->cells[1]);
    }

    #[Test]
    public function aQueryThatHidesTheChosenRowKeepsItChosen(): void
    {
        $hidden = $this->harness()->press('down', 'down', 'enter')->type('coffee')->widget();

        self::assertInstanceOf(FilterableTable::class, $hidden);
        self::assertSame('1 of 3', $hidden->summary(), 'precondition: the query hides the train');
        self::assertSame('Train', $hidden->selectedRow()?->cells[1]);
    }

    #[Test]
    public function clearingTheQueryShowsTheChosenRowChosenAgain(): void
    {
        $hidden = $this->harness()->press('down', 'down', 'enter')->type('coffee')->widget();
        self::assertInstanceOf(FilterableTable::class, $hidden);

        $cleared = $hidden->withQuery('');
        $frame = new Frame(40, 8);
        $cleared->render($frame, new Rect(0, 0, 40, 8), RenderContext::unfocused(Theme::dark()));
        $screen = ScreenBuffer::fromFrame($frame);
        $train = $screen->findOrFail('Train');

        self::assertSame('Train', $cleared->selectedRow()?->cells[1]);
        self::assertSame(
            Theme::dark()->selectedStyle->backgroundValue(),
            $screen->styleAt($train['column'], $train['row'])->backgroundValue(),
            'the table draws the train as chosen again',
        );
    }

    #[Test]
    public function ofTwoRowsWithTheSameTextTheOneChosenStaysChosenThroughAQuery(): void
    {
        $firstCoffee = Row::new('Coffee');
        $secondCoffee = Row::new('Coffee');
        $table = WidgetHarness::for(FilterableTable::new(
            [Column::new('Merchant', Constraint::flex())],
            [$firstCoffee, Row::new('Train'), $secondCoffee],
        ), 40, 8)->press('down', 'down', 'enter')->type('coffee')->widget();
        self::assertInstanceOf(FilterableTable::class, $table);

        self::assertSame($secondCoffee, $table->selectedRow(), 'while the query shows both');
        self::assertSame($secondCoffee, $table->withQuery('')->selectedRow(), 'and once it is cleared');
    }

    #[Test]
    public function aReloadThatReordersTheRowsKeepsTheChoiceOnItsRow(): void
    {
        $chosen = $this->harness()->press('down', 'down', 'enter')->widget();
        self::assertInstanceOf(FilterableTable::class, $chosen);

        $reordered = $chosen->withRows([
            Row::new('2026-10-03', 'Train'),
            Row::new('2026-10-01', 'Office rent'),
            Row::new('2026-10-02', 'Coffee, Vienna'),
        ]);

        self::assertSame('Train', $reordered->selectedRow()?->cells[1]);
    }

    #[Test]
    public function reloadingWithoutTheChosenRowLetsTheChoiceGo(): void
    {
        $chosen = $this->harness()->press('down', 'down', 'enter')->widget();
        self::assertInstanceOf(FilterableTable::class, $chosen);

        $reloaded = $chosen->withRows([Row::new('2026-10-01', 'Office rent')]);

        self::assertNull($reloaded->selectedRow());
        self::assertNull(
            $reloaded->withRows([Row::new('2026-10-01', 'Office rent'), Row::new('2026-10-02', 'Coffee, Vienna'), Row::new('2026-10-03', 'Train')])->selectedRow(),
            'the choice is gone, not waiting for the row to come back',
        );
    }

    #[Test]
    public function aHostsQueryLeavesTheCursorOnARowThatStillMatchesWhileTypingGoesToTheTop(): void
    {
        $onTrain = $this->harness()->press('down', 'down')->widget();
        self::assertInstanceOf(FilterableTable::class, $onTrain);

        $typed = WidgetHarness::for($onTrain, 40, 8)->type('r')->widget();
        self::assertInstanceOf(FilterableTable::class, $typed);

        self::assertSame('2 of 3', $onTrain->withQuery('r')->summary());
        self::assertSame('Train', $onTrain->withQuery('r')->cursorRow()?->cells[1], 'Office rent matches first, but Train still matches');
        self::assertSame('Office rent', $typed->cursorRow()?->cells[1], 'the reader typing goes to the best match');
    }

    #[Test]
    public function aQueryThatKeepsNothingSaysSo(): void
    {
        $screen = $this->harness()->type('zzz')->screen();

        self::assertNotNull($screen->find('No matches'));
    }

    #[Test]
    public function aTableWithNoRowsSaysWhatTheHostAsked(): void
    {
        $empty = FilterableTable::new([Column::new('Name', Constraint::flex())], [])->withNoDataText('Nothing loaded yet');

        self::assertNotNull(WidgetHarness::for($empty, 40, 8)->screen()->find('Nothing loaded yet'));
    }

    #[Test]
    public function escapeClearsTheQuery(): void
    {
        $table = $this->harness()->type('coffee')->press('esc')->widget();

        self::assertInstanceOf(FilterableTable::class, $table);
        self::assertSame('', $table->query());
        self::assertSame('3 of 3', $table->summary());
    }

    #[Test]
    public function theHintsNameMovingChoosingAndClearingAFilter(): void
    {
        self::assertSame(['↑/↓' => 'move', 'Enter' => 'choose', 'Ctrl+U/Esc' => 'clear filter'], $this->harness()->type('t')->focusedHints());
    }

    #[Test]
    public function inVimModeJMovesTheTableUntilSlashHandsTheKeysToTheField(): void
    {
        $table = $this->harness()->widget();
        self::assertInstanceOf(FilterableTable::class, $table);
        $table = $table->withGlobalKeybindings(VimMode::keybindings());

        foreach (['j', '/', 't', 'r'] as $key) {
            $table = $table->update(new KeyMsg($key), Rect::fromSize(40, 8))->model;
        }

        self::assertSame('tr', $table->query());
        self::assertSame('Train', $table->cursorRow()?->cells[1], 'j moved the cursor, and the query keeps its row');
    }

    #[Test]
    public function enterThatHandsTheKeysBackFromTheFieldChoosesNothing(): void
    {
        $table = $this->harness()->widget();
        self::assertInstanceOf(FilterableTable::class, $table);
        $searching = $table->withGlobalKeybindings(VimMode::keybindings())->update(new KeyMsg('/'), Rect::fromSize(40, 8))->model;

        self::assertFalse($searching->commits(new KeyMsg('enter')));
    }

    #[Test]
    public function aClickOnARowPutsTheCursorThere(): void
    {
        $table = $this->harness()->click('Train')->widget();

        self::assertInstanceOf(FilterableTable::class, $table);
        self::assertSame('Train', $table->cursorRow()?->cells[1]);
    }

    #[Test]
    public function theFieldIsUnderlinedOnlyWhileTheTableHasTheKeyboard(): void
    {
        $table = $this->harness()->widget();
        $focused = new Frame(40, 8);
        $unfocused = new Frame(40, 8);

        $table->render($focused, Rect::fromSize(40, 8), RenderContext::focused(Theme::dark()));
        $table->render($unfocused, Rect::fromSize(40, 8), RenderContext::unfocused(Theme::dark()));

        self::assertSame(Attr::UNDERLINE, $focused->attributes[0] & Attr::UNDERLINE);
        self::assertSame(0, $unfocused->attributes[0] & Attr::UNDERLINE);
    }

    #[Test]
    public function reloadedRowsKeepTheCursorOnTheRowItWasOn(): void
    {
        $table = $this->harness()->press('down', 'down')->widget();
        self::assertInstanceOf(FilterableTable::class, $table);

        $reloaded = $table->withRows([Row::new('2026-10-03', 'Train'), Row::new('2026-10-04', 'Hotel')]);

        self::assertSame(0, $reloaded->cursorSourceIndex());
        self::assertSame('2 of 2', $reloaded->summary());
    }

    #[Test]
    public function aHostCanSayWhatAQueryThatKeptNothingReads(): void
    {
        $table = $this->harness()->type('zzz')->widget();
        self::assertInstanceOf(FilterableTable::class, $table);

        self::assertNotNull(WidgetHarness::for($table->withNoMatchText('No expense matches'), 40, 8)->screen()->find('No expense matches'));
    }

    #[Test]
    public function aHostCanNameWhatTheEmptyFieldAsksFor(): void
    {
        $table = $this->harness()->widget();
        self::assertInstanceOf(FilterableTable::class, $table);

        self::assertNotNull(WidgetHarness::for($table->withPlaceholder('Filter merchant'), 40, 8)->screen()->find('Filter merchant'));
    }

    #[Test]
    public function aMatcherHandedInDecidesWhatTheQueryKeeps(): void
    {
        $table = $this->harness()->widget();
        self::assertInstanceOf(FilterableTable::class, $table);

        $everything = $table->withMatcher(new class implements Matcher {
            public function matching(string $query, array $haystack): array
            {
                return array_keys($haystack);
            }
        })->withQuery('nothing like it');

        self::assertSame('3 of 3', $everything->summary());
    }

    #[Test]
    public function theRowsTheQueryKeptAreThereToAddUp(): void
    {
        $table = $this->harness()->type('r')->widget();

        self::assertInstanceOf(FilterableTable::class, $table);
        self::assertSame(['Office rent', 'Train'], array_map(static fn (Row $row): string|Span => $row->cells[1], $table->matchingRows()));
    }

    #[Test]
    public function aQueryFindsTheTextOfAStyledCell(): void
    {
        $table = FilterableTable::new(
            [Column::new('Merchant', Constraint::flex())],
            [Row::new(Span::token('Office rent', StyleToken::Accent)), Row::new('Train')],
        );

        self::assertSame('1 of 2', $table->withQuery('rent')->summary());
    }

    #[Test]
    public function squeezedByItsNeighboursTheTableStillShowsARowUnderItsHeader(): void
    {
        $table = $this->harness()->widget();
        self::assertInstanceOf(FilterableTable::class, $table);

        $screen = WidgetHarness::for(Panel::new()->add($table)->add(Text::new('after')), 40, 6)->screen();

        self::assertNotNull($screen->find('Office rent'));
    }

    #[Test]
    public function inALayoutItAsksForNoMoreRoomThanItsFloor(): void
    {
        $table = $this->harness()->widget();
        self::assertInstanceOf(FilterableTable::class, $table);

        self::assertSame(4, Layout::column($table)->measureHeight(40), 'it scrolls its own rows, so it must not ask a layout for all of them');
    }

    private function harness(): WidgetHarness
    {
        return WidgetHarness::for(FilterableTable::new(
            [Column::new('Date', Constraint::exact(10)), Column::new('Merchant', Constraint::flex())],
            [Row::new('2026-10-01', 'Office rent'), Row::new('2026-10-02', 'Coffee, Vienna'), Row::new('2026-10-03', 'Train')],
        ), 40, 8);
    }
}
