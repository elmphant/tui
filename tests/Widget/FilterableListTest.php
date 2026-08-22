<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Layout\Layout;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseButton;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Search\Matcher;
use Elmphant\Tui\Testing\ScreenBuffer;
use Elmphant\Tui\Widget\ChildDispatcher;
use Elmphant\Tui\Widget\FilterableList;
use Elmphant\Tui\Widget\FilterBar;
use Elmphant\Tui\Widget\ListItem;
use Elmphant\Tui\Widget\SimpleListItem;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FilterableListTest extends TestCase
{
    private const WIDTH = 40;
    private const HEIGHT = 10;

    /**
     * The bar, a blank row, then the first item's title.
     */
    private const FIRST_ITEM_ROW = 2;

    #[Test]
    public function itNamesTheKeysItAnswers(): void
    {
        self::assertSame(
            ['↑/↓' => 'move', 'Enter' => 'choose'],
            FilterableList::new([])->hints(),
        );
    }

    #[Test]
    public function aListWithSomethingTypedInItNamesTheKeyThatClearsIt(): void
    {
        $filtered = FilterableList::new([new SimpleListItem('alpha')])->withQuery('al');

        self::assertSame(
            ['↑/↓' => 'move', 'Enter' => 'choose', 'Ctrl+U/Esc' => 'clear filter'],
            $filtered->hints(),
            'the field is this widget’s own, so the key that empties it is a key this widget answers',
        );
    }

    #[Test]
    public function narrowsToTheItemsMatchingTheQuery(): void
    {
        $list = $this->typed($this->commands(), 'warm');

        self::assertSame('1 of 3', $list->summary());
        self::assertStringContainsString('cache:warmup', $this->render($list));
        self::assertStringNotContainsString('user:create', $this->render($list));
    }

    #[Test]
    public function aSpaceTypesRatherThanSelects(): void
    {
        $list = $this->typed($this->commands(), 'cache warm');

        self::assertSame('cache warm', $list->query());
        self::assertSame('1 of 3', $list->summary());
    }

    #[Test]
    public function homeAndEndMoveTheCaretRatherThanTheCursor(): void
    {
        $list = $this->typed($this->commands(), 'cache');

        $atHome = $this->press($list, Key::Home);

        self::assertSame(0, $atHome->sourceIndex(), 'The cursor stayed on the first match');
        self::assertSame('cache', $atHome->query(), 'and the query is untouched');

        self::assertSame(0, $this->press($atHome, Key::End)->sourceIndex());
    }

    #[Test]
    public function theArrowsWalkTheFilteredList(): void
    {
        $list = $this->press($this->typed($this->commands(), 'cache'), Key::Down);

        self::assertSame(1, $list->sourceIndex(), 'cache:flush is the second source item');

        self::assertSame(0, $this->press($list, Key::Up)->sourceIndex());
    }

    #[Test]
    public function theCursorAddressesTheSourceListNotTheFilteredOne(): void
    {
        $list = $this->typed($this->commands(), 'create');

        self::assertSame(2, $list->sourceIndex(), 'user:create is third in the source list');
    }

    #[Test]
    public function aQueryMatchingNothingSelectsNothing(): void
    {
        $list = $this->typed($this->commands(), 'nonsense');

        self::assertNull($list->sourceIndex());
        self::assertSame('0 of 3', $list->summary());
        self::assertStringContainsString('No matches', $this->render($list));
    }

    #[Test]
    public function aHostCanSayWhatAQueryThatKeptNothingReads(): void
    {
        $list = $this->typed($this->commands()->withNoMatchText('No command by that name'), 'nonsense');

        self::assertStringContainsString('No command by that name', $this->render($list));
    }

    #[Test]
    public function aNewQueryPutsTheCursorBackOnTheBestMatch(): void
    {
        $moved = $this->press($this->typed($this->commands(), 'cache'), Key::Down);
        self::assertSame(1, $moved->sourceIndex());

        $narrowed = $this->typed($moved, ' warm');

        self::assertSame(0, $narrowed->sourceIndex(), 'The cursor went back to the top of the new results');
    }

    #[Test]
    public function backspaceWidensTheResultsAgain(): void
    {
        $list = $this->press($this->typed($this->commands(), 'warm'), Key::Backspace);

        self::assertSame('war', $list->query());
        self::assertSame('1 of 3', $list->summary());
    }

    #[Test]
    public function anEmptyQueryKeepsEveryItem(): void
    {
        $list = FilterableList::new($this->commandItems());

        self::assertSame('3 of 3', $list->summary());
        self::assertSame(0, $list->sourceIndex());
    }

    #[Test]
    public function theBarSitsAboveTheListWithABlankRowBetween(): void
    {
        $screen = $this->rows(FilterableList::new($this->commandItems()));

        self::assertStringContainsString('Filter…', $screen[0]);
        self::assertSame('', trim($screen[1]), 'A blank row keeps the field off the first item');
        self::assertStringContainsString('cache:warmup', $screen[2]);
    }

    #[Test]
    public function theBarShowsTheQuery(): void
    {
        $bar = $this->rows($this->typed($this->commands(), 'cache'))[0];

        self::assertSame('cache', trim($bar));
    }

    #[Test]
    public function aQueryAndAHighlightPutBackReadOutAsTheyWentIn(): void
    {
        $narrowed = $this->press($this->typed($this->commands(), 'cache'), Key::Down);
        self::assertSame('cache', $narrowed->query());
        self::assertSame(1, $narrowed->sourceIndex());

        $restored = $this->commands()->withQuery('cache')->withSourceIndex(1);

        self::assertSame('cache', $restored->query());
        self::assertSame(1, $restored->sourceIndex());
        self::assertSame('2 of 3', $restored->summary(), 'the query narrowed the list again');
    }

    #[Test]
    public function highlightsTheMatchersBestMatchRatherThanTheFirstSourceItem(): void
    {
        $ranked = new class implements Matcher {
            public function matching(string $query, array $haystack): array
            {
                return [2, 0];
            }
        };

        $list = FilterableList::new($this->commandItems())->withMatcher($ranked)->withQuery('anything');

        self::assertSame(2, $list->sourceIndex(), 'user:create was ranked first');
        self::assertSame('2 of 3', $list->summary());
    }

    #[Test]
    public function aHostsGlobalRowNavigationWalksTheFilteredList(): void
    {
        $dispatcher = new ChildDispatcher(new Keybindings([Actions::CURSOR_DOWN => ['ctrl+n']]));

        $moved = $dispatcher->dispatchOne($this->commands(), new KeyMsg('n', ctrl: true), $this->area())->child;
        self::assertInstanceOf(FilterableList::class, $moved);

        self::assertSame(1, $moved->sourceIndex(), 'ctrl+n reached the list');
        self::assertSame('', $moved->query(), 'and was not typed into the field');
    }

    #[Test]
    public function shiftAndAnArrowPagesTheListLikeItDoesOnItsOwn(): void
    {
        $paged = $this->send($this->commands(), new KeyMsg(Key::Down, shift: true));

        self::assertGreaterThan(0, $paged->sourceIndex(), 'the key the list pages on reaches it here too');
        self::assertSame('', $paged->query(), 'and was not typed into the field');
    }

    #[Test]
    public function filtersOncePerQueryHoweverOftenTheResultIsRead(): void
    {
        $matcher = new CountingMatcher();
        $list = FilterableList::new($this->commandItems())->withMatcher($matcher)->withQuery('cache');

        $matcher->calls = 0;
        $list->sourceIndex();
        $list->cursorItem();
        $list->summary();
        $list->sourceIndex();

        self::assertSame(0, $matcher->calls, 'reading what the filter kept costs nothing');
    }

    #[Test]
    public function aChoiceSurvivesTheReaderTypingOnInTheFilter(): void
    {
        $picked = $this->commands()->withSelectedValue('cache:flush');

        $typed = $this->typed($picked, 'user');

        self::assertSame(
            'cache:flush',
            $typed->selectedItem()?->title(),
            'narrowing the list is not choosing from it, so the choice holds still',
        );
    }

    #[Test]
    public function theHighlightFollowsTheFilterWhileTheChoiceDoesNot(): void
    {
        $list = $this->commands()->withSelectedValue('cache:flush');

        $typed = $this->typed($list, 'user');

        self::assertSame('user:create', $typed->cursorItem()?->title(), 'the cursor sits on the only match');
        self::assertSame('cache:flush', $typed->selectedItem()?->title(), 'and the choice is elsewhere, which is the point');
    }

    #[Test]
    public function escapeClearsATypedQueryAndKeepsEverythingElseWhereItWas(): void
    {
        $list = $this->commands()->withQuery('cache');

        $cleared = $this->press($list, Key::Escape);

        self::assertSame('', $cleared->query(), 'the first escape belongs to the query');
        self::assertNotSame($list, $cleared, 'and a changed model is how it says it took the key');
    }

    #[Test]
    public function escapeOverAnEmptyQueryIsLeftForWhoeverIsAbove(): void
    {
        $list = $this->commands();

        $answered = $list->update(new KeyMsg(Key::Escape), $this->area());

        self::assertSame($list, $answered->model, 'nothing to clear, so nothing is claimed');
        self::assertNull($answered->command);
    }

    #[Test]
    public function readsAsWhatWasChosenRatherThanHowManyMatched(): void
    {
        $list = $this->commands()->withEmptyCaption('choose a command');

        self::assertSame('choose a command', $list->caption());
        self::assertSame('cache:flush', $list->activated('cache:flush')->caption());
        self::assertSame('3 of 3', $list->summary(), 'summary answers a different question and keeps doing so');
    }

    #[Test]
    public function anOptionItNeverOfferedIsNotOneItCanChoose(): void
    {
        $list = $this->commands()->withSelectedValue('cache:flush');

        self::assertFalse($list->hasOption('user:delete'));
        self::assertSame(
            'cache:flush',
            $list->activated('user:delete')->caption(),
            'a name it does not hold leaves the choice alone',
        );
    }

    #[Test]
    public function namesTheItemUnderAPointerAsTheFilterLeftTheList(): void
    {
        $narrowed = $this->typed($this->commands(), 'cache');
        $firstRow = $this->area()->y + FilterBar::HEIGHT + 1;
        $itemRows = $this->commandItems()[0]->height(self::WIDTH);

        self::assertSame(
            'cache:flush',
            $narrowed->optionAt(new MouseMsg(0, $firstRow + $itemRows, MouseAction::Press, MouseButton::Left), $this->area()),
            'the rows below the first match belong to the second, addressed by its own name',
        );
        self::assertNull(
            $narrowed->optionAt(new MouseMsg(0, $this->area()->y, MouseAction::Press, MouseButton::Left), $this->area()),
            'the filter field is not part of the list',
        );
    }

    #[Test]
    public function measuresItsWidestTitleAndTheRowsItsMatchesNeed(): void
    {
        $list = $this->commands();
        $itemRows = $this->commandItems()[0]->height(self::WIDTH);

        self::assertSame(12, $list->popupWidth(), 'the widest title decides, since that is what the list draws');
        self::assertSame(
            FilterBar::HEIGHT + 1 + 3 * $itemRows,
            $list->popupHeight(self::WIDTH),
            'the field, its gap, and a row for every match',
        );
        self::assertSame(
            FilterBar::HEIGHT + 1 + 1,
            $this->typed($list, 'zzz')->popupHeight(self::WIDTH),
            'a query that matched nothing still leaves a row to say so',
        );
    }

    #[Test]
    public function chosingWithTheKeyboardRecordsTheRowUnderTheCursor(): void
    {
        $list = $this->send($this->commands(), new KeyMsg(Key::Down));

        $picked = $this->send($list, new KeyMsg(Key::Enter));

        self::assertSame('cache:flush', $picked->selectedItem()?->filterValue());
    }

    #[Test]
    public function aListToldNotToBeClearableKeepsWhatItHolds(): void
    {
        $list = $this->commands()->withClearable(false)->withSelectedValue('cache:warmup');

        $again = $list->activated('cache:warmup');

        self::assertSame(
            'cache:warmup',
            $again->selectedItem()?->filterValue(),
            'a list that must answer stays answered when the row it holds is chosen again',
        );
    }

    #[Test]
    public function aClearableListLetsThePickedRowBePutBackDown(): void
    {
        $list = $this->commands()->withClearable()->withSelectedValue('cache:warmup');

        $cleared = $list->activated('cache:warmup');
        $moved = $list->activated('user:create');

        self::assertNull($cleared->selectedItem(), 'choosing the row it already holds puts it back down');
        self::assertSame('user:create', $moved->selectedItem()?->filterValue(), 'any other row still picks');
    }

    #[Test]
    public function enterOverAQueryThatMatchedNothingFinishesNothing(): void
    {
        self::assertTrue(
            $this->commands()->commits(new KeyMsg(Key::Enter)),
            'with a match under the cursor, enter is what finishes the choice',
        );
        self::assertFalse(
            $this->typed($this->commands(), 'zzz')->commits(new KeyMsg(Key::Enter)),
            'enter still maps to SELECT, but there is nothing under the cursor to choose',
        );
    }

    #[Test]
    public function anEmptyQueryNeverConsultsTheMatcher(): void
    {
        $matcher = new CountingMatcher();

        $list = FilterableList::new($this->commandItems())->withMatcher($matcher);

        self::assertSame(0, $matcher->calls, 'everything matches an empty query, so there is nothing to ask');
        self::assertSame('3 of 3', $list->summary(), 'and the list comes through whole');
    }

    #[Test]
    public function aWhitespaceOnlyQueryIsTreatedAsEmptyRatherThanPassedToTheMatcher(): void
    {
        $matcher = new CountingMatcher();

        $list = FilterableList::new($this->commandItems())->withMatcher($matcher)->withQuery('   ');

        self::assertSame(0, $matcher->calls, 'a reader who typed only spaces has narrowed nothing');
        self::assertSame('3 of 3', $list->summary());
    }

    #[Test]
    public function inALayoutItAsksForNoMoreRoomThanItsConstraintClaims(): void
    {
        $stack = Layout::vertical($this->commands());

        self::assertSame(
            FilterBar::HEIGHT + 1 + 1,
            $stack->measureHeight(self::WIDTH),
            'a flexible list scrolls, so it must not tell a layout it needs every row its items would fill',
        );
    }

    #[Test]
    public function aClickLeavesNoMarkerBehindOnceTheCursorMovesOn(): void
    {
        $theme = Theme::dark();
        $clicked = $this->commands()->update(
            new MouseMsg(1, self::FIRST_ITEM_ROW, MouseAction::Press, MouseButton::Left),
            $this->area(),
        )->model;

        self::assertInstanceOf(FilterableList::class, $clicked);

        $moved = $this->press($clicked, Key::Down);
        $frame = new Frame(self::WIDTH, self::HEIGHT);
        $moved->render($frame, $this->area(), new RenderContext($theme));

        self::assertNotSame(
            $theme->accent->foregroundValue(),
            $frame->foregrounds[self::FIRST_ITEM_ROW * self::WIDTH + 1],
            'The row a click left behind must not stay marked once the cursor has moved on',
        );
    }

    #[Test]
    public function aMatcherNamingTheSameItemTwiceStillShowsItOnce(): void
    {
        $repeating = new class implements Matcher {
            public function matching(string $query, array $haystack): array
            {
                return [0, 0];
            }
        };

        $list = FilterableList::new($this->commandItems())->withMatcher($repeating)->withQuery('anything');

        self::assertSame('1 of 3', $list->summary(), 'One item named twice is one match');
        self::assertSame(1, substr_count($this->render($list), 'cache:warmup'));
    }

    #[Test]
    public function pickingTheSecondOfTwoItemsThatReadTheSamePicksThatOne(): void
    {
        $list = FilterableList::new([
            new SimpleListItem('Same', 'first'),
            new SimpleListItem('Same', 'second'),
        ])->withFocus(true);

        $onSecond = $this->press($list, Key::Down);
        self::assertSame(1, $onSecond->sourceIndex(), 'the cursor is on the second');

        $picked = $this->press($onSecond, Key::Enter);

        self::assertSame('second', $picked->selectedItem()?->description());
    }

    #[Test]
    public function aPlainStringIsRefusedWhereItIsHandedIn(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('FilterableList takes ListItem instances; item 1 is string');

        FilterableList::new(self::itemsFromUntypedData());
    }

    #[Test]
    public function aFloatIsToldEveryRowTheMatchesNeedWhereAStackIsNot(): void
    {
        $list = $this->commands();

        self::assertSame(8, $list->popupHeight(self::WIDTH), 'the bar, a blank row, and two rows per item');
        self::assertSame(4, $this->typed($list, 'warm')->popupHeight(self::WIDTH), 'and it shrinks to what the query kept');
    }

    /**
     * @return list<SimpleListItem>
     */
    private function commandItems(): array
    {
        return [
            new SimpleListItem('cache:warmup', 'Warms every cache'),
            new SimpleListItem('cache:flush', 'Empties every cache'),
            new SimpleListItem('user:create', 'Adds a user'),
        ];
    }

    private function commands(): FilterableList
    {
        return FilterableList::new($this->commandItems())->withFocus(true);
    }

    private function typed(FilterableList $list, string $text): FilterableList
    {
        foreach (preg_split('//u', $text, flags: \PREG_SPLIT_NO_EMPTY) ?: [] as $character) {
            $list = $this->send($list, new KeyMsg($character));
        }

        return $list;
    }

    private function press(FilterableList $list, Key $key): FilterableList
    {
        return $this->send($list, new KeyMsg($key));
    }

    private function send(FilterableList $list, KeyMsg $message): FilterableList
    {
        $updated = $list->update($message, $this->area())->model;
        self::assertInstanceOf(FilterableList::class, $updated);

        return $updated;
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
        $items = [new SimpleListItem('kept'), 'plain'];

        return $items;
    }

    private function area(): Rect
    {
        return Rect::fromSize(self::WIDTH, self::HEIGHT);
    }

    private function render(FilterableList $list): string
    {
        return implode("\n", $this->rows($list));
    }

    /**
     * @return list<string>
     */
    private function rows(FilterableList $list): array
    {
        $frame = new Frame(self::WIDTH, self::HEIGHT);
        $list->render($frame, $this->area(), new RenderContext(Theme::dark()));

        $screen = ScreenBuffer::fromFrame($frame);
        $rows = [];
        for ($row = 0; $row < self::HEIGHT; ++$row) {
            $rows[] = $screen->getLine($row);
        }

        return $rows;
    }
}
