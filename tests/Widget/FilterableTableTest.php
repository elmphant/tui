<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Core\Component;
use Elmphant\Tui\Core\Model;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Rendering\Attr;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Search\Matcher;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Filter\FilterableTable;
use Elmphant\Tui\Widget\Filter\FilterBar;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FilterableTableTest extends TestCase
{
    #[Test]
    public function aTableWithSomethingTypedInItNamesEveryKeyThatClearsIt(): void
    {
        $filtered = FilterableTable::new(['Name'], [['alpha']])->withQuery('al');

        self::assertSame(
            ['Ctrl+U/Esc' => 'clear filter'],
            $filtered->hints(),
            'the field is this widget’s own, so the key that empties it is a key this widget answers',
        );
    }

    #[Test]
    public function takingFocusKeepsTheFieldTheCaretSitsIn(): void
    {
        $table = $this->routes()->withField(FilterBar::field('ajax', true)->withCursor(1));

        self::assertSame(1, $table->withFocus(true)->field()->cursor(), 'a later builder must not drop the field');
    }

    #[Test]
    public function filtersItsRowsOnceForOneFrame(): void
    {
        $counting = new class implements Matcher {
            public int $calls = 0;

            public function matching(string $query, array $haystack): array
            {
                ++$this->calls;

                return array_keys($haystack);
            }
        };

        $table = $this->routes()->withMatcher($counting)->withQuery('ajax');
        $table->render(new Frame(60, 12), new Rect(0, 0, 60, 12), new RenderContext(Theme::dark()));

        self::assertSame(1, $counting->calls, 'One frame must run the filter once');
    }

    #[Test]
    public function anUnfocusedTableClaimsNoCaretForAFieldItWasHandedFocused(): void
    {
        $table = $this->routes()->withFocus(false)->withField(FilterBar::field('', true));

        $frame = new Frame(60, 12);
        $table->render($frame, new Rect(0, 0, 60, 12), new RenderContext(Theme::dark()));

        self::assertNull($frame->cursorPosition, 'Only the pane the reader is typing in may show the caret');
    }

    #[Test]
    public function keepsOnlyRowsWhereEveryTermMatchesSomeCell(): void
    {
        $matched = $this->routes()->withQuery('ajax post')->matchingRows();

        self::assertSame([['ajax_bookmark', 'POST', '/ajax/bookmark']], $matched);
    }

    #[Test]
    public function matchesBySubstring(): void
    {
        self::assertSame([['ajax_bookmark', 'POST', '/ajax/bookmark']], $this->routes()->withQuery('bookmark')->matchingRows());
        self::assertSame([], $this->routes()->withQuery('bookmrk')->matchingRows());
    }

    #[Test]
    public function filtersThroughTheMatcherItWasGiven(): void
    {
        $lastLetter = new class implements Matcher {
            public function matching(string $query, array $haystack): array
            {
                return array_keys(array_filter(
                    $haystack,
                    static fn (string $entry): bool => str_ends_with($entry, $query),
                ));
            }
        };

        self::assertSame(
            [['ajax_bookmark', 'POST', '/ajax/bookmark']],
            $this->routes()->withMatcher($lastLetter)->withQuery('/ajax/bookmark')->matchingRows(),
        );
    }

    #[Test]
    public function keepsTheRowsInTheRankTheMatcherReturns(): void
    {
        $reversed = new class implements Matcher {
            public function matching(string $query, array $haystack): array
            {
                return [2, 0];
            }
        };

        self::assertSame(
            [
                ['record_edit', 'POST', '/record/edit'],
                ['ajax_bookmark', 'POST', '/ajax/bookmark'],
            ],
            $this->routes()->withMatcher($reversed)->withQuery('post')->matchingRows(),
            'best match first is the Matcher contract, the promise every filterable widget keeps',
        );
    }

    #[Test]
    public function ignoresCase(): void
    {
        self::assertCount(1, $this->routes()->withQuery('AJAX_BOOK')->matchingRows());
    }

    #[Test]
    public function countsWhatItKeptOutOfWhatItHas(): void
    {
        self::assertSame('3 of 3', $this->routes()->summary());
        self::assertSame('1 of 3', $this->routes()->withQuery('ajax post')->summary());
        self::assertSame('0 of 3', $this->routes()->withQuery('nothing')->summary());
    }

    #[Test]
    public function drawsTheFilterBarOnTheRowAboveTheTable(): void
    {
        $rows = $this->screen($this->routes(), 60, 12);

        self::assertStringContainsString('Filter…', $rows[0]);
        self::assertStringContainsString('3 of 3', $rows[0]);
        self::assertStringStartsWith('┌', ltrim($rows[1]), 'The table starts on the very next row');
    }

    #[Test]
    public function offersAFieldToTypeIntoRatherThanEmptySpace(): void
    {
        $rows = $this->screen($this->routes(), 60, 12);

        self::assertStringContainsString('Filter…', $rows[0], 'An empty query still reads as a field');
        self::assertStringNotContainsString('Filter…', $this->screen($this->routes()->withQuery('ajax'), 60, 12)[0]);
    }

    #[Test]
    public function underlinesTheFieldWhileThePaneIsActive(): void
    {
        self::assertTrue($this->fieldIsUnderlined($this->routes()->withFocus(true)));
        self::assertFalse($this->fieldIsUnderlined($this->routes()), 'An inactive pane draws no field to type into');
    }

    #[Test]
    public function holdsTheColumnWidthsItStartedWithWhileFiltering(): void
    {
        $table = FilterableTable::new(
            ['Alias', 'Namespace'],
            [['core', 'Acme\Core\ViewHelpers'], ['f', 'X\Y']],
        );

        $unfiltered = $this->screen($table, 90, 12);
        $filtered = $this->screen($table->withQuery('X\Y'), 90, 12);

        self::assertSame(
            mb_strlen($unfiltered[1]),
            mb_strlen($filtered[1]),
            'A table that resized under every keystroke would move the rows the user is reading',
        );
        self::assertSame(mb_strlen($unfiltered[0]), mb_strlen($filtered[0]), 'The bar holds still with it');
    }

    #[Test]
    public function endsTheBarWhereTheTableEnds(): void
    {
        $narrower = FilterableTable::new(
            ['Alias', 'Namespace'],
            [['core', 'Acme\Core\ViewHelpers'], ['formvh', 'Acme\Form\ViewHelpers']],
        );
        $rows = $this->screen($narrower, 90, 12);

        self::assertSame(
            mb_strlen($rows[1]),
            mb_strlen($rows[0]),
            'The count sits at the table edge, not out in the empty pane',
        );
    }

    #[Test]
    public function drawsNoTableNarrowerThanItsOwnFilterBar(): void
    {
        $rows = $this->screen(FilterableTable::new(['Locale'], [['de_DE.utf8'], ['en_US.UTF-8']]), 90, 12);

        self::assertSame(
            mb_strlen($rows[0]),
            mb_strlen($rows[1]),
            'A table narrower than the bar above it reads as two unrelated things',
        );
    }

    #[Test]
    public function keepsTheBarWideEnoughForItsOwnControls(): void
    {
        $bar = $this->barRow(FilterableTable::new(['A'], [['x']]), 90);

        self::assertSame('Filter…', mb_substr($bar, 0, 7), 'The field sits at the left edge');
        self::assertSame('1 of 1', mb_substr($bar, 14, 6), 'and the count ends a bar that holds room for nothing else');
    }

    #[Test]
    public function leavesTheCaretAloneWhenTheBarIsScrolledOutOfSight(): void
    {
        $frame = new Frame(60, 12);
        $frame->pushClip(new Rect(0, 4, 60, 8));

        $this->routes()->withFocus(true)->render($frame, Rect::fromSize(60, 12), new RenderContext(Theme::dark()));

        self::assertNull($frame->cursorPosition, 'A caret placed outside its pane is drawn on whatever is there');
    }

    #[Test]
    public function drawsOnlyTheRowsTheFilterKept(): void
    {
        $screen = implode("\n", $this->screen($this->routes()->withQuery('ajax post'), 60, 12));

        self::assertStringContainsString('ajax_bookmark', $screen);
        self::assertStringNotContainsString('record_edit', $screen);
    }

    #[Test]
    public function theBarHoldsAFieldAtOneEndACountAtTheOtherAndNothingBetween(): void
    {
        $bar = $this->barRow($this->routes(), 50);

        self::assertSame('Filter…', mb_substr($bar, 0, 7), 'The field sits at the left edge');
        self::assertSame('3 of 3', mb_substr($bar, 37, 6), 'and the count ends where the table does');
        self::assertSame('', trim(mb_substr($bar, 7, 30)), 'with nothing in between claiming to be a control');
    }

    #[Test]
    public function showsTheQueryInPlaceOfThePlaceholder(): void
    {
        $bar = $this->barRow($this->routes()->withQuery('ajax')->withFocus(true), 50);

        self::assertSame('ajax', mb_substr($bar, 0, 4));
        self::assertSame('2 of 3', mb_substr($bar, 37, 6), 'The count follows the filter');
    }

    #[Test]
    public function drawsTheTableOnlyWithinTheHeightBelowTheBar(): void
    {
        $frame = new Frame(50, 6);
        $this->routes()->render($frame, new Rect(0, 0, 50, 2), new RenderContext(Theme::dark()));

        self::assertSame('┌', mb_substr($this->row($frame, 1, 50), 0, 1), 'One row of table fits below the bar');

        for ($row = 2; $row < 6; ++$row) {
            self::assertSame('', trim($this->row($frame, $row, 50)), "Nothing is drawn on row $row, past the area");
        }
    }

    #[Test]
    public function anAreaOnlyTallEnoughForTheBarDrawsNoTable(): void
    {
        $frame = new Frame(50, 4);
        $this->routes()->render($frame, new Rect(0, 0, 50, 1), new RenderContext(Theme::dark()));

        self::assertSame('Filter…', mb_substr($this->row($frame, 0, 50), 0, 7));

        for ($row = 1; $row < 4; ++$row) {
            self::assertSame('', trim($this->row($frame, $row, 50)), "Row $row is left alone");
        }
    }

    #[Test]
    public function putsTheCursorAfterTheQueryWhileFocused(): void
    {
        $frame = new Frame(50, 1);
        $this->routes()->withQuery('ajax')->withFocus(true)
            ->render($frame, new Rect(0, 0, 50, 1), RenderContext::focused(Theme::dark()));

        self::assertNotNull($frame->cursorPosition);
        self::assertSame(4, $frame->cursorPosition->col, 'One column past the four typed characters');
        self::assertSame(0, $frame->cursorPosition->row);
    }

    #[Test]
    public function theCursorStopsAtTheEndOfTheFieldHoweverLongTheQuery(): void
    {
        $frame = new Frame(50, 1);
        // The bar is 43 wide, the count takes 6 and a gap of 3 sits before it,
        // so the field spans 34 columns however long the query is.
        $fieldWidth = 34;

        $this->routes()->withQuery(str_repeat('x', 40))->withFocus(true)
            ->render($frame, new Rect(0, 0, 50, 1), RenderContext::focused(Theme::dark()));

        self::assertNotNull($frame->cursorPosition);
        self::assertSame($fieldWidth - 1, $frame->cursorPosition->col);
    }

    #[Test]
    public function anUnfocusedBarShowsNoCursorAndNoUnderline(): void
    {
        $frame = new Frame(50, 1);
        $this->routes()->withQuery('ajax')
            ->render($frame, new Rect(0, 0, 50, 1), new RenderContext(Theme::dark()));

        self::assertNull($frame->cursorPosition, 'Nothing is typing into a bar that is not focused');
        self::assertSame(0, $frame->attributes[0] & Attr::UNDERLINE);
    }

    #[Test]
    public function aFocusedFieldIsUnderlinedAcrossItsWholeWidth(): void
    {
        $frame = new Frame(50, 1);
        $this->routes()->withQuery('ajax')->withFocus(true)
            ->render($frame, new Rect(0, 0, 50, 1), RenderContext::focused(Theme::dark()));

        $fieldWidth = 34;

        self::assertSame(Attr::UNDERLINE, $frame->attributes[0] & Attr::UNDERLINE, 'Under the text');
        self::assertSame(
            Attr::UNDERLINE,
            $frame->attributes[$fieldWidth - 1] & Attr::UNDERLINE,
            'And under the empty tail',
        );
        self::assertSame(0, $frame->attributes[$fieldWidth] & Attr::UNDERLINE, 'But no further than the field');
    }

    #[Test]
    public function aTypedQueryIsBrighterThanThePlaceholder(): void
    {
        $typed = new Frame(50, 1);
        $this->routes()->withQuery('ajax')->withFocus(true)
            ->render($typed, new Rect(0, 0, 50, 1), RenderContext::focused(Theme::dark()));

        $placeholder = new Frame(50, 1);
        $this->routes()->withFocus(true)
            ->render($placeholder, new Rect(0, 0, 50, 1), RenderContext::focused(Theme::dark()));

        self::assertNotSame(
            $placeholder->foregrounds[0],
            $typed->foregrounds[0],
            'A query is drawn in the base tone, the placeholder dim',
        );
    }

    #[Test]
    public function aQueryOfNothingButSpacesKeepsEveryRow(): void
    {
        $all = $this->routes()->matchingRows();

        self::assertSame($all, $this->routes()->withQuery('')->matchingRows());
        self::assertSame($all, $this->routes()->withQuery('   ')->matchingRows());
        self::assertCount(3, $all);
    }

    #[Test]
    public function drawsTheFilterFieldItIsGivenSoACaretCanSitWhereTheReaderPutIt(): void
    {
        $field = FilterBar::field('ajax', true)->withCursor(2);
        $table = $this->routes()->withField($field);

        self::assertSame('ajax', $table->query(), 'the field it is given is the query it filters by');
        self::assertSame(2, $table->field()->cursor(), 'and the caret is where the field says, not at the end');
    }

    #[Test]
    public function aMatcherNamingTheSameRowTwiceStillKeepsItOnce(): void
    {
        $repeating = new class implements Matcher {
            public function matching(string $query, array $haystack): array
            {
                return [0, 0];
            }
        };

        $table = $this->routes()->withMatcher($repeating)->withQuery('anything');

        self::assertSame([['ajax_bookmark', 'POST', '/ajax/bookmark']], $table->matchingRows());
        self::assertSame('1 of 3', $table->summary());
    }

    #[Test]
    public function aMatcherNamingAnIndexBeyondTheRowsNamesNoRowForIt(): void
    {
        $stale = new class implements Matcher {
            public function matching(string $query, array $haystack): array
            {
                return $haystack === [] ? [] : [0, 9];
            }
        };

        $table = $this->routes()->withMatcher($stale)->withQuery('anything');

        self::assertSame(
            [['ajax_bookmark', 'POST', '/ajax/bookmark']],
            $table->matchingRows(),
            'an index past the rows names no row of table',
        );
        self::assertSame('1 of 3', $table->summary());
    }

    #[Test]
    public function aFieldHandedInIsUnderlinedByTheSameRuleAsOneBuiltHere(): void
    {
        $handedIn = FilterBar::field('ajax', false);

        self::assertTrue($this->fieldIsUnderlined($this->routes()->withFocus(true)->withField($handedIn)));
        self::assertFalse(
            $this->fieldIsUnderlined($this->routes()->withField($handedIn)),
            'A host driving the caret does not decide whether the pane is active',
        );
    }

    #[Test]
    public function typingIntoAFocusedTableNarrowsIt(): void
    {
        $table = $this->routes()->withFocus(true);

        $narrowed = $table->update(new KeyMsg('f'), Rect::fromSize(40, 10))->model;

        self::assertSame('f', $narrowed->query());
        self::assertSame([['ajax_flush', 'GET', '/ajax/flush']], $narrowed->matchingRows());
    }

    #[Test]
    public function typingPastAnUnfocusedTableLeavesItAlone(): void
    {
        $table = $this->routes();

        $result = $table->update(new KeyMsg('f'), Rect::fromSize(40, 10));

        self::assertSame($table, $result->model);
    }

    #[Test]
    public function aHostCanBindItLikeAnyOtherInteractiveWidget(): void
    {
        $table = $this->routes();

        $component = Component::bind('routes', $table, static fn (Model $app, FilterableTable $replacement): Model => $app);

        self::assertEquals($table->sizing(), $component->sizing(), 'The component stands in for the table it was given');
    }

    /**
     * The query was kept twice — a string of its own and the field it was typed into
     * — so setting only the string left the bar reading one filter while the rows
     * were narrowed by another.
     */
    #[Test]
    public function clearingTheQueryClearsWhatTheFieldShows(): void
    {
        $typedIn = FilterableTable::new(['Name'], [['alpha'], ['beta']])
            ->withField(TextInput::new()->withValue('alpha'));
        self::assertSame('alpha', $typedIn->field()->value(), 'precondition: a filter was typed');

        self::assertSame('', $typedIn->withQuery('')->field()->value());
    }

    #[Test]
    public function escapeClearsATypedQueryAndKeepsEverythingElseWhereItWas(): void
    {
        $table = $this->routes()->withFocus(true)->withQuery('ajax');

        $cleared = $table->update(new KeyMsg(Key::Escape), new Rect(0, 0, 40, 6))->model;

        self::assertInstanceOf(FilterableTable::class, $cleared);
        self::assertSame('', $cleared->query(), 'the first escape belongs to the query');
        self::assertNotSame($table, $cleared, 'and a changed model is how it says it took the key');
    }

    #[Test]
    public function escapeOverAnEmptyQueryIsLeftForWhoeverIsAbove(): void
    {
        $table = $this->routes()->withFocus(true);

        $answered = $table->update(new KeyMsg(Key::Escape), new Rect(0, 0, 40, 6));

        self::assertSame($table, $answered->model, 'nothing to clear, so nothing is claimed');
        self::assertNull($answered->command);
    }

    private function fieldIsUnderlined(FilterableTable $table): bool
    {
        $frame = new Frame(60, 12);
        $context = $table->isFocused() ? RenderContext::focused(Theme::dark()) : RenderContext::unfocused(Theme::dark());
        $table->render($frame, Rect::fromSize(60, 12), $context);

        for ($column = 0; $column < 60; ++$column) {
            if (($frame->attributes[$column] & Attr::UNDERLINE) !== 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function screen(FilterableTable $table, int $width, int $height): array
    {
        $frame = new Frame($width, $height);
        $table->render($frame, Rect::fromSize($width, $height), new RenderContext(Theme::dark()));

        $rows = [];
        for ($row = 0; $row < $height; ++$row) {
            $rows[] = rtrim(implode('', array_slice($frame->characters, $row * $width, $width)));
        }

        return $rows;
    }

    private function barRow(FilterableTable $table, int $width): string
    {
        $frame = new Frame($width, 1);
        $table->render($frame, new Rect(0, 0, $width, 1), new RenderContext(Theme::dark()));

        return $this->row($frame, 0, $width);
    }

    private function row(Frame $frame, int $row, int $width): string
    {
        $line = '';

        for ($column = 0; $column < $width; ++$column) {
            $line .= $frame->characters[$row * $width + $column];
        }

        return $line;
    }

    private function routes(): FilterableTable
    {
        return FilterableTable::new(
            ['Name', 'Method', 'Path'],
            [
                ['ajax_bookmark', 'POST', '/ajax/bookmark'],
                ['ajax_flush', 'GET', '/ajax/flush'],
                ['record_edit', 'POST', '/record/edit'],
            ],
        );
    }
}
