<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Search\Matcher;
use Elmphant\Tui\Testing\ScreenBuffer;
use Elmphant\Tui\Widget\Dropdown;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DropdownTest extends TestCase
{
    #[Test]
    public function closedItShowsTheSelectedValueOnOneRow(): void
    {
        $dropdown = Dropdown::new(['json', 'yaml'])->withSelected('yaml');

        self::assertSame(1, $dropdown->constraint()->min);
        self::assertStringContainsString('yaml', self::screen($dropdown)->getLine(0));
    }

    #[Test]
    public function spaceOpensAFloatingListBelowTheRow(): void
    {
        $opened = self::opened(Dropdown::new(['json', 'yaml']));

        self::assertTrue($opened->isOpen());
        self::assertStringContainsString('yaml', self::screen($opened)->getPlainText(), 'The popup drew');
    }

    #[Test]
    public function enterPicksTheOptionUnderTheCursorAndCloses(): void
    {
        $area = new Rect(0, 0, 20, 1);
        $opened = self::opened(Dropdown::new(['json', 'yaml']));

        $moved = $opened->update(new KeyMsg(Key::Down), $area)->model;
        $picked = $moved->update(new KeyMsg(Key::Enter), $area)->model;

        self::assertSame('yaml', $picked->selectedValue());
        self::assertFalse($picked->isOpen());
    }

    #[Test]
    public function theFirstEscapeClearsATypedFilterAndKeepsTheListOpen(): void
    {
        $area = new Rect(0, 0, 20, 1);

        $narrowed = self::opened(Dropdown::new(['json', 'yaml', 'toml']))
            ->update(new KeyMsg('y'), $area)->model;

        self::assertTrue($narrowed->hasFilterText(), 'Precondition: the field holds a query');
        self::assertStringNotContainsString('json', self::screen($narrowed)->getPlainText(), 'Precondition: the query hid json');

        $cleared = $narrowed->update(new KeyMsg(Key::Escape), $area)->model;

        self::assertTrue($cleared->isOpen(), 'the first escape widens the list rather than throwing it away');
        self::assertFalse($cleared->hasFilterText());
        self::assertStringContainsString('json', self::screen($cleared)->getPlainText(), 'and the option it hid is offered again');
    }

    #[Test]
    public function theSecondEscapeClosesTheDropdown(): void
    {
        $area = new Rect(0, 0, 20, 1);

        $closed = self::opened(Dropdown::new(['json', 'yaml', 'toml']))
            ->update(new KeyMsg('y'), $area)->model
            ->update(new KeyMsg(Key::Escape), $area)->model
            ->update(new KeyMsg(Key::Escape), $area)->model;

        self::assertFalse($closed->isOpen());
        self::assertNull($closed->selectedValue(), 'and cancelling twice still picks nothing');
    }

    #[Test]
    public function escapeClosesWithoutChangingTheValue(): void
    {
        $area = new Rect(0, 0, 20, 1);
        $opened = self::opened(Dropdown::new(['json', 'yaml'])->withSelected('json'));

        $cancelled = $opened->update(new KeyMsg(Key::Down), $area)->model
            ->update(new KeyMsg(Key::Escape), $area)->model;

        self::assertSame('json', $cancelled->selectedValue());
        self::assertFalse($cancelled->isOpen());
    }

    #[Test]
    public function typingNarrowsTheListAndTheCursorFollowsIt(): void
    {
        $area = new Rect(0, 0, 20, 1);

        $typed = self::opened(Dropdown::new(['json', 'yaml', 'yml']))
            ->update(new KeyMsg('y'), $area)->model
            ->update(new KeyMsg('m'), $area)->model;

        $picked = $typed->update(new KeyMsg(Key::Enter), $area)->model;

        self::assertSame('yml', $picked->selectedValue(), 'Only yml matches "ym"');
    }

    #[Test]
    public function theFirstOptionOfferedIsTheMatcherSBestMatch(): void
    {
        $area = new Rect(0, 0, 20, 1);

        $ranked = new class implements Matcher {
            public function matching(string $query, array $haystack): array
            {
                return [2, 0];
            }
        };

        $picked = Dropdown::new(['first', 'second', 'third'])
            ->withMatcher($ranked)
            ->withFocus(true)
            ->update(new KeyMsg(' '), $area)->model
            ->update(new KeyMsg('x'), $area)->model
            ->update(new KeyMsg(Key::Enter), $area)->model;

        self::assertSame('third', $picked->selectedValue(), 'The matcher ranked it first');
    }

    #[Test]
    public function theQueryIsMatchedAgainstTheLabelTheUserCanSee(): void
    {
        $area = new Rect(0, 0, 20, 1);

        $picked = Dropdown::new(['a1b2', 'c3d4'])
            ->withLabels(['a1b2' => 'Production', 'c3d4' => 'Staging'])
            ->withFocus(true)
            ->update(new KeyMsg(' '), $area)->model
            ->update(new KeyMsg('S'), $area)->model
            ->update(new KeyMsg('t'), $area)->model
            ->update(new KeyMsg(Key::Enter), $area)->model;

        self::assertSame('c3d4', $picked->selectedValue(), 'Typing the label picks its opaque value');
    }

    #[Test]
    public function withNothingSelectedItShowsThePlaceholderDim(): void
    {
        $theme = Theme::dark();

        self::assertNotSame(
            $theme->base->foregroundValue(),
            $theme->dim->foregroundValue(),
            'The theme must distinguish the two, or this test proves nothing',
        );

        $frame = self::frameFor(Dropdown::new(['json'])->withPlaceholder('choose a format'), $theme);
        $selected = self::frameFor(Dropdown::new(['json'])->withSelected('json'), $theme);

        self::assertSame($theme->dim->foregroundValue(), self::foregroundOfFirstCell($frame));
        self::assertSame($theme->base->foregroundValue(), self::foregroundOfFirstCell($selected));
    }

    #[Test]
    public function aFocusedEmptyDropdownRendersInAccentNotDim(): void
    {
        $theme = Theme::dark();

        self::assertNotSame(
            $theme->accent->foregroundValue(),
            $theme->dim->foregroundValue(),
            'The theme must distinguish the two, or this test proves nothing',
        );

        $focused = self::frameFor(Dropdown::new(['json'])->withFocus(true), $theme);
        $unfocused = self::frameFor(Dropdown::new(['json']), $theme);

        self::assertNotSame(
            self::foregroundOfFirstCell($unfocused),
            self::foregroundOfFirstCell($focused),
            'Focus must change how the row renders',
        );
        self::assertSame($theme->accent->foregroundValue(), self::foregroundOfFirstCell($focused));
    }

    #[Test]
    public function aFocusedDropdownWithAValueSelectedAlsoRendersInAccent(): void
    {
        $theme = Theme::dark();

        self::assertNotSame(
            $theme->accent->foregroundValue(),
            $theme->base->foregroundValue(),
            'The theme must distinguish the two, or this test proves nothing',
        );

        $frame = self::frameFor(Dropdown::new(['json'])->withSelected('json')->withFocus(true), $theme);

        self::assertSame($theme->accent->foregroundValue(), self::foregroundOfFirstCell($frame));
    }

    #[Test]
    public function backspaceWidensTheListAgain(): void
    {
        $area = new Rect(0, 0, 20, 1);

        $picked = self::opened(Dropdown::new(['json', 'yaml']))
            ->update(new KeyMsg('y'), $area)->model
            ->update(new KeyMsg(Key::Backspace), $area)->model
            ->update(new KeyMsg(Key::Enter), $area)->model;

        self::assertSame('json', $picked->selectedValue(), 'The whole list is back, cursor at the top');
    }

    #[Test]
    public function theCursorStopsAtBothEndsOfTheList(): void
    {
        $area = new Rect(0, 0, 20, 1);
        $opened = self::opened(Dropdown::new(['json', 'yaml']));

        $atTop = $opened->update(new KeyMsg(Key::Up), $area)->model;
        $atBottom = $opened
            ->update(new KeyMsg(Key::Down), $area)->model
            ->update(new KeyMsg(Key::Down), $area)->model;

        self::assertSame('json', $atTop->update(new KeyMsg(Key::Enter), $area)->model->selectedValue());
        self::assertSame('yaml', $atBottom->update(new KeyMsg(Key::Enter), $area)->model->selectedValue());
    }

    #[Test]
    public function freeTextCommitsAQueryThatMatchesNoOption(): void
    {
        $area = new Rect(0, 0, 20, 1);

        $dropdown = self::opened(Dropdown::new(['json', 'yaml'])->withFreeText(true));
        $typed = $dropdown->update(new KeyMsg('x'), $area)->model;

        self::assertSame('x', $typed->update(new KeyMsg(Key::Enter), $area)->model->selectedValue());
    }

    #[Test]
    public function aClosedSetIgnoresEnterOnAQueryThatMatchesNothing(): void
    {
        $area = new Rect(0, 0, 20, 1);

        $typed = self::opened(Dropdown::new(['json', 'yaml']))->update(new KeyMsg('x'), $area)->model;

        self::assertNull($typed->update(new KeyMsg(Key::Enter), $area)->model->selectedValue());
    }

    #[Test]
    public function walkingALongListKeepsTheCursorInsideTheVisibleWindow(): void
    {
        $area = new Rect(0, 0, 40, 1);
        $screen = Rect::fromSize(60, 14);
        $options = array_map(static fn (int $index): string => 'option' . $index, range(1, 36));

        $dropdown = Dropdown::new($options)
            ->withFocus(true)
            ->update(new KeyMsg(' '), $area)->model;

        for ($step = 0; $step < 20; ++$step) {
            $dropdown = $dropdown->update(new KeyMsg(Key::Down, screen: $screen), $area)->model;
        }

        $frame = new Frame(60, 14);
        $context = new RenderContext(Theme::dark());
        $context->renderRoot($dropdown, $frame, $area);

        self::assertStringContainsString(
            'option21',
            ScreenBuffer::fromFrame($frame)->getPlainText(),
            'The highlighted option is still drawn after walking past the window',
        );
    }

    #[Test]
    public function reopeningLandsOnTheSelectedValueScrolledIntoView(): void
    {
        $area = new Rect(0, 0, 20, 1);
        $screen = Rect::fromSize(20, 8);
        $options = array_map(static fn (int $index): string => 'option' . $index, range(1, 20));

        $dropdown = Dropdown::new($options)
            ->withSelected('option18')
            ->withFocus(true);

        $reopened = $dropdown->update(new KeyMsg(' ', screen: $screen), $area)->model;

        $frame = new Frame(20, 8);
        (new RenderContext(Theme::dark()))->renderRoot($reopened, $frame, $area);

        self::assertStringContainsString(
            'option18',
            self::popupBody($frame),
            'The selection must be drawn inside the popup, scrolled into view, not merely cursored off-screen',
        );
    }

    #[Test]
    public function enterOpensAClosedDropdown(): void
    {
        $area = new Rect(0, 0, 20, 1);
        $dropdown = Dropdown::new(['json', 'yaml'])->withFocus(true);

        $opened = $dropdown->update(new KeyMsg(Key::Enter), $area)->model;

        self::assertTrue($opened->isOpen(), 'Enter is the opener a reader actually tries');
    }

    #[Test]
    public function rightNoLongerOpensTheDropdown(): void
    {
        $area = new Rect(0, 0, 20, 1);
        $dropdown = Dropdown::new(['json', 'yaml'])->withFocus(true);

        $result = $dropdown->update(new KeyMsg(Key::Right), $area)->model;

        self::assertFalse($result->isOpen(), 'Right is tree/menu vocabulary, not this control\'s opener');
    }

    #[Test]
    public function everyActionCanBeRebound(): void
    {
        $area = new Rect(0, 0, 20, 1);

        $rebound = Dropdown::new(['json', 'yaml'])
            ->withFocus(true)
            ->withKeybindings(new Keybindings([
                Actions::TOGGLE => ['ctrl+o'],
                Actions::SELECT => ['tab'],
                Actions::CANCEL => ['ctrl+c'],
                Actions::FOCUS_NEXT => ['ctrl+n'],
                Actions::FOCUS_PREV => ['ctrl+p'],
                Actions::DELETE_CHAR_BACKWARD => ['ctrl+h'],
            ]));

        $ignored = $rebound->update(new KeyMsg(' '), $area)->model;
        self::assertFalse($ignored->isOpen(), 'Space no longer opens it');

        $opened = $rebound->update(new KeyMsg('o', ctrl: true), $area)->model;
        self::assertTrue($opened->isOpen(), 'but the rebound key does');

        $picked = $opened
            ->update(new KeyMsg('n', ctrl: true), $area)->model
            ->update(new KeyMsg(Key::Tab), $area)->model;

        self::assertSame('yaml', $picked->selectedValue(), 'Rebound move and select');

        $movedBack = $opened
            ->update(new KeyMsg('n', ctrl: true), $area)->model
            ->update(new KeyMsg('p', ctrl: true), $area)->model
            ->update(new KeyMsg(Key::Tab), $area)->model;

        self::assertSame('json', $movedBack->selectedValue(), 'Rebound move back');

        $queryDeleted = $opened
            ->update(new KeyMsg('y'), $area)->model
            ->update(new KeyMsg('h', ctrl: true), $area)->model
            ->update(new KeyMsg(Key::Tab), $area)->model;

        self::assertSame('json', $queryDeleted->selectedValue(), 'Rebound backspace emptied the query, so the first option is back');

        $cancelled = $opened->update(new KeyMsg('c', ctrl: true), $area)->model;
        self::assertFalse($cancelled->isOpen(), 'and rebound cancel');
    }

    #[Test]
    public function anOpenDropdownDrawsExactlyOnePopupPerFrame(): void
    {
        $opened = self::opened(Dropdown::new(['json', 'yaml'])->withSelected('json'));

        $frame = new Frame(30, 6);
        $context = new RenderContext(Theme::dark());
        $context->beginFrame(0.0);
        $context->renderRoot($opened, $frame, new Rect(0, 0, 20, 1));
        $context->commitFloatPlacements();

        $placements = $context->floatPlacements();

        self::assertCount(1, $placements, 'one popup per frame — a self-queueing float would draw again');
        self::assertSame(1, $placements[0]->placement->y, 'and it starts on the row below the anchor');
        self::assertStringContainsString(
            '╭',
            ScreenBuffer::fromFrame($frame)->getLine(1),
            'the popup top border is on that row',
        );
    }

    #[Test]
    public function anOpenDropdownNeverRedrawsItsClosedRowBelowTheAnchor(): void
    {
        $opened = self::opened(Dropdown::new(['json', 'yaml'])->withSelected('json'));

        $frame = new Frame(30, 6);
        $context = new RenderContext(Theme::dark());
        $context->renderRoot($opened, $frame, new Rect(0, 0, 20, 1));

        self::assertStringNotContainsString(
            '▾ json',
            ScreenBuffer::fromFrame($frame)->getLine(2),
            'The closed row is drawn once, at the anchor only',
        );
    }

    #[Test]
    public function isFocusedDefaultsToFalse(): void
    {
        self::assertFalse(Dropdown::new(['a', 'b'])->isFocused());
    }

    #[Test]
    public function losingFocusClosesAnOpenDropdown(): void
    {
        $opened = self::opened(Dropdown::new(['json', 'yaml']));

        $unfocused = $opened->withFocus(false);

        self::assertFalse($unfocused->isOpen(), 'Losing focus must close it, never leave or force it open');
    }

    #[Test]
    public function anUnfocusedDropdownIgnoresTheToggleKey(): void
    {
        $area = new Rect(0, 0, 20, 1);
        $unfocused = Dropdown::new(['json', 'yaml']);

        $result = $unfocused->update(new KeyMsg(' '), $area)->model;

        self::assertFalse($result->isOpen(), 'A dropdown without focus must not react to keys at all');
    }

    #[Test]
    public function openingSelectsTheFirstOptionAsTheStartingCursor(): void
    {
        $area = new Rect(0, 0, 20, 1);
        $opened = self::opened(Dropdown::new(['json', 'yaml']));

        $picked = $opened->update(new KeyMsg(Key::Enter), $area)->model;

        self::assertSame('json', $picked->selectedValue(), 'Opening resets the cursor to the top option');
    }

    #[Test]
    public function pressingUpAfterDownReturnsToTheFirstOption(): void
    {
        $area = new Rect(0, 0, 20, 1);
        $opened = self::opened(Dropdown::new(['json', 'yaml']));

        $backAtTop = $opened
            ->update(new KeyMsg(Key::Down), $area)->model
            ->update(new KeyMsg(Key::Up), $area)->model;

        self::assertSame(
            'json',
            $backAtTop->update(new KeyMsg(Key::Enter), $area)->model->selectedValue(),
            'Up must move the cursor back, not just fail to move it further down',
        );
    }

    #[Test]
    public function anUnboundControlKeyWhileOpenIsIgnoredWithoutError(): void
    {
        $area = new Rect(0, 0, 20, 1);
        $opened = self::opened(Dropdown::new(['json', 'yaml']));

        $result = $opened->update(new KeyMsg('z', ctrl: true), $area)->model;

        self::assertTrue($result->isOpen(), 'An unbound key must be ignored, not left unhandled');
        self::assertNull($result->selectedValue());
    }

    #[Test]
    public function anAreaExactlyOneCellWideStillDrawsTheMarker(): void
    {
        $frame = new Frame(5, 5);
        $context = new RenderContext(Theme::dark());

        Dropdown::new(['a'])->withSelected('a')->render($frame, new Rect(0, 0, 1, 1), $context);

        self::assertNotSame(' ', $frame->characters[0], 'A width of exactly 1 is still enough room to draw');
    }

    #[Test]
    public function aZeroHeightAreaDrawsNothing(): void
    {
        $frame = new Frame(5, 3);
        $context = new RenderContext(Theme::dark());

        Dropdown::new(['a'])->withSelected('a')->render($frame, new Rect(0, 0, 5, 0), $context);

        self::assertSame(' ', $frame->characters[0], 'Zero height is no room at all');
    }

    #[Test]
    public function theClosedRowShowsTheLabelNotTheRawValue(): void
    {
        $dropdown = Dropdown::new(['x'])->withLabels(['x' => 'Nicer Label'])->withSelected('x');

        self::assertStringContainsString('Nicer Label', self::screen($dropdown)->getPlainText());
        self::assertStringNotContainsString('▾ x ', self::screen($dropdown)->getPlainText());
    }

    #[Test]
    public function theMarkerPrecedesTheValueOnTheClosedRow(): void
    {
        $frame = self::frameFor(Dropdown::new(['json', 'yaml'])->withSelected('yaml'), Theme::dark());

        self::assertSame('▾', ScreenBuffer::fromFrame($frame)->getTextAt(0, 0, 1));
    }

    #[Test]
    public function anEmptyQueryNeverConsultsTheMatcher(): void
    {
        $opened = self::opened(Dropdown::new(['json', 'yaml'])->withMatcher(self::neverMatches()));

        self::assertStringContainsString(
            'yaml',
            self::screen($opened)->getPlainText(),
            'An empty query must short-circuit before the matcher runs',
        );
    }

    #[Test]
    public function aWhitespaceOnlyQueryIsTreatedAsEmptyNotPassedToTheMatcher(): void
    {
        $area = new Rect(0, 0, 20, 1);

        $typed = self::opened(Dropdown::new(['json', 'yaml'])->withMatcher(self::neverMatches()))
            ->update(new KeyMsg(' '), $area)->model;

        self::assertStringContainsString(
            'yaml',
            self::screen($typed)->getPlainText(),
            'Whitespace alone must also short-circuit before the matcher runs',
        );
    }

    #[Test]
    public function backspaceRemovesExactlyOneCharacterNotTheWholeQuery(): void
    {
        $area = new Rect(0, 0, 20, 1);
        $seenQueries = [];

        $dropdown = self::opened(Dropdown::new(['a', 'b'])->withMatcher(self::queryRecordingMatcher($seenQueries)))
            ->update(new KeyMsg('y'), $area)->model
            ->update(new KeyMsg('a'), $area)->model
            ->update(new KeyMsg(Key::Backspace), $area)->model;

        $dropdown->render(new Frame(20, 10), $area, new RenderContext(Theme::dark()));

        self::assertSame('y', end($seenQueries), 'One character removed, not the whole query');
    }

    #[Test]
    public function backspaceRemovesOneMultibyteCharacterNotOneByte(): void
    {
        $area = new Rect(0, 0, 20, 1);
        $seenQueries = [];

        $dropdown = self::opened(Dropdown::new(['a', 'b'])->withMatcher(self::queryRecordingMatcher($seenQueries)))
            ->update(new KeyMsg('日'), $area)->model
            ->update(new KeyMsg('本'), $area)->model
            ->update(new KeyMsg(Key::Backspace), $area)->model;

        $dropdown->render(new Frame(20, 10), $area, new RenderContext(Theme::dark()));

        self::assertSame('日', end($seenQueries), 'One multibyte character removed, not one byte of it');
    }

    #[Test]
    public function ctrlLetterWhileOpenIsNotTypedIntoTheQuery(): void
    {
        $area = new Rect(0, 0, 20, 1);
        $seenQueries = [];

        $dropdown = self::opened(Dropdown::new(['a', 'b'])->withMatcher(self::queryRecordingMatcher($seenQueries)))
            ->update(new KeyMsg('x'), $area)->model
            ->update(new KeyMsg('a', ctrl: true), $area)->model;

        $dropdown->render(new Frame(20, 10), $area, new RenderContext(Theme::dark()));

        self::assertSame('x', end($seenQueries), 'ctrl+a must not be typed into the filter');
    }

    #[Test]
    public function altLetterWhileOpenIsNotTypedIntoTheQuery(): void
    {
        $area = new Rect(0, 0, 20, 1);
        $seenQueries = [];

        $dropdown = self::opened(Dropdown::new(['a', 'b'])->withMatcher(self::queryRecordingMatcher($seenQueries)))
            ->update(new KeyMsg('x'), $area)->model
            ->update(new KeyMsg('a', alt: true), $area)->model;

        $dropdown->render(new Frame(20, 10), $area, new RenderContext(Theme::dark()));

        self::assertSame('x', end($seenQueries), 'alt+a must not be typed into the filter');
    }

    #[Test]
    public function freeTextRejectsAWhitespaceOnlyQuery(): void
    {
        $area = new Rect(0, 0, 20, 1);

        $dropdown = self::opened(Dropdown::new([])->withFreeText(true))
            ->update(new KeyMsg(' '), $area)->model;

        $picked = $dropdown->update(new KeyMsg(Key::Enter), $area)->model;

        self::assertNull($picked->selectedValue(), 'A whitespace-only query must not become the free-text selection');
    }

    #[Test]
    public function scrollFollowsTheCursorEvenWithOnlyOneVisibleRow(): void
    {
        $area = new Rect(0, 0, 20, 1);
        $screen = Rect::fromSize(20, 6);
        $options = array_map(static fn (int $index): string => 'option' . $index, range(1, 10));

        $dropdown = Dropdown::new($options)
            ->withFocus(true)
            ->update(new KeyMsg(' '), $area)->model
            ->update(new KeyMsg(Key::Down, screen: $screen), $area)->model;

        $frame = new Frame(20, 6);
        (new RenderContext(Theme::dark()))->renderRoot($dropdown, $frame, $area);

        self::assertStringContainsString(
            'option2',
            ScreenBuffer::fromFrame($frame)->getPlainText(),
            'A single visible row must still follow the cursor down',
        );
    }

    #[Test]
    public function movingUpAfterTheFilteredListShrinksSelfCorrectsAStaleCursor(): void
    {
        $area = new Rect(0, 0, 20, 1);

        $opened = self::opened(Dropdown::new(['a', 'b'])->withLabels(['a' => 'q-a', 'b' => 'q-b']))
            ->update(new KeyMsg('q'), $area)->model
            ->update(new KeyMsg(Key::Down), $area)->model;

        // Cursor is now legitimately 1 ('b'), with both options matching "q".
        // Shrinking the match set to just "a" touches neither cursor nor scroll.
        $shrunk = $opened->withLabels(['a' => 'q-a', 'b' => 'no-match']);

        $corrected = $shrunk->update(new KeyMsg(Key::Up), $area)->model;
        $picked = $corrected->update(new KeyMsg(Key::Enter), $area)->model;

        self::assertSame(
            'a',
            $picked->selectedValue(),
            'Up must self-correct a cursor left stale by a list that just shrank to one item',
        );
    }

    #[Test]
    public function scrollDoesNotAdvanceOnTheFirstStepDownWithinAWideWindow(): void
    {
        $area = new Rect(0, 0, 20, 1);
        $screen = Rect::fromSize(20, 14);
        $options = array_map(static fn (int $index): string => 'option' . $index, range(1, 10));

        $dropdown = Dropdown::new($options)
            ->withFocus(true)
            ->update(new KeyMsg(' '), $area)->model
            ->update(new KeyMsg(Key::Down, screen: $screen), $area)->model;

        $frame = new Frame(20, 14);
        (new RenderContext(Theme::dark()))->renderRoot($dropdown, $frame, $area);
        $screen = ScreenBuffer::fromFrame($frame)->getPlainText();

        self::assertStringContainsString(
            'option1 ',
            $screen,
            'Cursor moved to option2, still well within the 3-row window starting at option1',
        );
        self::assertStringNotContainsString(
            'option10',
            $screen,
            'The window must not jump to the end of the list after a single step down',
        );
    }

    #[Test]
    public function scrollShowingFollowsTheCursorWithExactArithmetic(): void
    {
        $area = new Rect(0, 0, 20, 1);
        $screen = Rect::fromSize(20, 14);
        $options = array_map(static fn (int $index): string => 'option' . $index, range(1, 10));

        $dropdown = Dropdown::new($options)
            ->withFocus(true)
            ->update(new KeyMsg(' '), $area)->model;

        for ($step = 0; $step < 5; ++$step) {
            $dropdown = $dropdown->update(new KeyMsg(Key::Down, screen: $screen), $area)->model;
        }

        $frame = new Frame(20, 14);
        (new RenderContext(Theme::dark()))->renderRoot($dropdown, $frame, $area);
        $drawn = ScreenBuffer::fromFrame($frame)->getPlainText();

        self::assertStringContainsString('option4', $drawn, 'Scroll landed on exactly 3: cursor 5 minus 3 rows plus 1');
        self::assertStringContainsString('option6', $drawn, 'and the 3-row window still ends on option6');
        self::assertStringNotContainsString('option3 ', $drawn, 'option3 has scrolled just out of view');
        self::assertStringNotContainsString('option7 ', $drawn, 'and the window has not overshot past option6');

        $dropdown = $dropdown
            ->update(new KeyMsg(Key::Up, screen: $screen), $area)->model
            ->update(new KeyMsg(Key::Up, screen: $screen), $area)->model
            ->update(new KeyMsg(Key::Up, screen: $screen), $area)->model;

        $frame2 = new Frame(20, 14);
        (new RenderContext(Theme::dark()))->renderRoot($dropdown, $frame2, $area);
        $drawn2 = ScreenBuffer::fromFrame($frame2)->getPlainText();

        self::assertStringContainsString('option3', $drawn2, 'Three ups from cursor 5 land on cursor 2, pulling scroll back to 2');
        self::assertStringNotContainsString('option6 ', $drawn2, 'and the window no longer reaches option6');
    }

    #[Test]
    public function typingAfterScrollingResetsTheWindowToTheTop(): void
    {
        $area = new Rect(0, 0, 40, 1);
        $screen = Rect::fromSize(60, 14);
        $options = array_map(static fn (int $index): string => 'option' . $index, range(1, 36));

        $dropdown = Dropdown::new($options)
            ->withFocus(true)
            ->update(new KeyMsg(' '), $area)->model;

        for ($step = 0; $step < 20; ++$step) {
            $dropdown = $dropdown->update(new KeyMsg(Key::Down, screen: $screen), $area)->model;
        }

        $typed = $dropdown->update(new KeyMsg('o'), $area)->model;

        $frame = new Frame(60, 14);
        $context = new RenderContext(Theme::dark());
        $context->renderRoot($typed, $frame, $area);

        self::assertStringContainsString(
            'option1 ',
            ScreenBuffer::fromFrame($frame)->getPlainText(),
            'Typing must reset the scroll to the top of the (still fully matching) list',
        );
    }

    /**
     * The anchor row (row 0) always redraws the current selection, open or
     * closed, so it would make a scroll-into-view assertion pass regardless
     * of what the popup itself shows. Only rows below it prove the popup drew.
     */
    private static function popupBody(Frame $frame): string
    {
        $screen = ScreenBuffer::fromFrame($frame);
        $rows = [];

        for ($row = 1; $row < $frame->height; ++$row) {
            $rows[] = $screen->getLine($row);
        }

        return implode("\n", $rows);
    }

    private static function neverMatches(): Matcher
    {
        return new class implements Matcher {
            public function matching(string $query, array $haystack): array
            {
                return [];
            }
        };
    }

    /**
     * @param list<string> $seenQueries
     */
    private static function queryRecordingMatcher(array &$seenQueries): Matcher
    {
        return new class($seenQueries) implements Matcher {
            /**
             * @param list<string> $seenQueries
             */
            public function __construct(private array &$seenQueries)
            {
            }

            public function matching(string $query, array $haystack): array
            {
                $this->seenQueries[] = $query;

                return array_keys($haystack);
            }
        };
    }

    private static function frameFor(Dropdown $dropdown, Theme $theme): Frame
    {
        $frame = new Frame(30, 6);
        (new RenderContext($theme))->renderRoot($dropdown, $frame, new Rect(0, 0, 20, 1));

        return $frame;
    }

    /**
     * The marker occupies cell 0 of row 0, and carries the row's style.
     */
    private static function foregroundOfFirstCell(Frame $frame): int
    {
        return $frame->foreground(0);
    }

    private static function screen(Dropdown $dropdown): ScreenBuffer
    {
        $frame = new Frame(30, 12);
        $context = new RenderContext(Theme::dark());
        $context->renderRoot($dropdown, $frame, new Rect(0, 0, 20, 1));

        return ScreenBuffer::fromFrame($frame);
    }

    private static function opened(Dropdown $dropdown): Dropdown
    {
        return $dropdown->withFocus(true)->update(new KeyMsg(' '), new Rect(0, 0, 20, 1))->model;
    }
}
