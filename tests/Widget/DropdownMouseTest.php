<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\FloatMouseMsg;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseButton;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Rendering\FloatPlacement;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Runtime\InputRouter;
use Elmphant\Tui\Testing\ScreenBuffer;
use Elmphant\Tui\Widget\Dropdown;
use Elmphant\Tui\Widget\DropdownPopup;
use Elmphant\Tui\Widget\FloatTarget;
use Elmphant\Tui\Widget\Overlay;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DropdownMouseTest extends TestCase
{
    #[Test]
    public function clickingAnOptionPicksIt(): void
    {
        $opened = self::opened();

        $clicked = self::clickRowOf($opened, 'yaml');

        self::assertSame('yaml', $clicked->selectedValue());
        self::assertFalse($clicked->isOpen());
    }

    #[Test]
    public function clickingExactlyTheFirstOptionRowPicksTheFirstOption(): void
    {
        $clicked = self::clickRowOf(self::opened(), 'json');

        self::assertSame('json', $clicked->selectedValue(), 'the first option row itself must be clickable');
    }

    #[Test]
    public function clickingTheClosedRowOpensItOnTheSelectionScrolledIntoView(): void
    {
        $anchor = new Rect(0, 0, 20, 1);
        $screen = Rect::fromSize(20, 8);
        $options = array_map(static fn (int $index): string => 'option' . $index, range(1, 20));

        $dropdown = Dropdown::new($options)->withSelected('option18')->withFocus(true);

        $clicked = $dropdown->update(
            new MouseMsg(2, 0, MouseAction::Press, MouseButton::Left, screen: $screen),
            $anchor,
        )->model;

        self::assertTrue($clicked->isOpen(), 'a click on the closed row opens the popup');

        $frame = new Frame(20, 8);
        (new RenderContext(Theme::dark()))->renderRoot($clicked, $frame, $anchor);

        $drawn = [];
        for ($row = 1; $row < $frame->height; ++$row) {
            $drawn[] = ScreenBuffer::fromFrame($frame)->getLine($row);
        }

        self::assertStringContainsString('option18', implode("\n", $drawn), 'it opens on the selection, scrolled into view');
    }

    #[Test]
    public function clickingAnUnfocusedClosedRowOpensIt(): void
    {
        $unfocused = Dropdown::new(['json', 'yaml']);

        $clicked = $unfocused->update(
            new MouseMsg(2, 0, MouseAction::Press, MouseButton::Left, screen: self::viewport()),
            self::anchor(),
        )->model;

        self::assertTrue(
            $clicked->isOpen(),
            'a host may apply click-focus only after dispatch, so the click itself must open it',
        );
    }

    #[Test]
    public function clickingElsewhereWhileClosedDoesNothing(): void
    {
        $dropdown = Dropdown::new(['json', 'yaml'])->withFocus(true);

        $clicked = $dropdown->update(
            new MouseMsg(2, 5, MouseAction::Press, MouseButton::Left, screen: self::viewport()),
            self::anchor(),
        )->model;

        self::assertFalse($clicked->isOpen(), 'a click away from the closed row must not open it');
    }

    #[Test]
    public function clickingTheOwnRowWhileOpenClosesItWithoutPicking(): void
    {
        $clicked = self::opened()->update(
            new MouseMsg(2, 0, MouseAction::Press, MouseButton::Left, screen: self::viewport()),
            self::anchor(),
        )->model;

        self::assertFalse($clicked->isOpen(), 'the closed row toggles');
        self::assertNull($clicked->selectedValue());
    }

    #[Test]
    public function clickingOutsideTheListClosesItWithoutPicking(): void
    {
        $clicked = self::opened()->update(
            new MouseMsg(2, 9, MouseAction::Press, MouseButton::Left, screen: self::viewport()),
            self::anchor(),
        )->model;

        self::assertNull($clicked->selectedValue());
        self::assertFalse($clicked->isOpen());
    }

    #[Test]
    public function clickingAwayCancelsEvenWithoutAScreenRectOnTheMessage(): void
    {
        $blind = Dropdown::new(['json', 'yaml'])
            ->withFocus(true)
            ->update(new KeyMsg(' '), self::anchor())->model;

        $clicked = $blind->update(
            new MouseMsg(2, 9, MouseAction::Press, MouseButton::Left),
            self::anchor(),
        )->model;

        self::assertFalse($clicked->isOpen(), 'dismissing never needed the geometry');
        self::assertNull($clicked->selectedValue());
    }

    #[Test]
    public function clickingTheBottomBorderOfACappedListPicksNothing(): void
    {
        $opened = self::listOf(self::manyOptions());
        $placement = self::drawnPlacement($opened);

        $clicked = self::clickRow($opened, $placement->bottom() - 1);

        self::assertNull($clicked->selectedValue(), 'the row below the last visible option is the border');
    }

    #[Test]
    public function clickingTheFilterRowPicksNothing(): void
    {
        $opened = self::listOf(self::manyOptions());
        $scrolled = self::wheelRow($opened, self::rowOf($opened, 'option1'), MouseAction::WheelDown, 5);
        $placement = self::drawnPlacement($scrolled);

        $onFilterRow = self::clickRow($scrolled, $placement->y + 1);

        self::assertNull($onFilterRow->selectedValue(), 'the query row must not map through the scroll offset');
        self::assertTrue($onFilterRow->isOpen(), 'and clicking inside the popup does not dismiss it');
    }

    #[Test]
    public function clickingARowPastTheEndOfADrawnListPicksNothing(): void
    {
        $opened = self::listOf(['a', 'b']);
        $lastOptionRow = self::rowOf($opened, 'b');

        $clicked = self::clickRow($opened, $lastOptionRow + 1);

        self::assertNull($clicked->selectedValue(), 'no option was drawn there');
    }

    #[Test]
    public function aMotionOverThePopupPassesBeneathAsAPlainMessage(): void
    {
        $opened = self::opened();
        $row = self::rowOf($opened, 'json');

        $result = $opened->update(
            self::routed($opened, new MouseMsg(2, $row, MouseAction::Motion, MouseButton::None)),
            self::anchor(),
        )->model;

        self::assertTrue($result->isOpen(), 'a motion over the popup must fall through, not throw');
        self::assertNull($result->selectedValue());
    }

    #[Test]
    public function theWheelScrollsByAsManyNotchesAsTheEventCarries(): void
    {
        $opened = self::listOf(self::manyOptions());

        $scrolled = self::wheelRow($opened, self::rowOf($opened, 'option1'), MouseAction::WheelDown, 3);

        self::assertStringContainsString('option8', self::draw($scrolled), 'three notches moved three rows');
        self::assertStringNotContainsString('option3 ', self::draw($scrolled), 'one notch would still show option3');
    }

    #[Test]
    public function wheelDownScrollsDownByExactlyOneNotch(): void
    {
        $opened = self::listOf(self::manyOptions());

        $scrolled = self::wheelRow($opened, self::rowOf($opened, 'option1'), MouseAction::WheelDown);

        $screen = self::draw($scrolled);
        self::assertStringContainsString('option2 ', $screen, 'option2 is now first');
        self::assertStringNotContainsString('option1 ', $screen, 'option1 scrolled out of view');
    }

    #[Test]
    public function wheelUpScrollsUpByExactlyOneNotch(): void
    {
        $opened = self::listOf(self::manyOptions());
        $row = self::rowOf($opened, 'option1');

        $scrolled = $opened;
        for ($step = 0; $step < 10; ++$step) {
            $scrolled = self::wheelRow($scrolled, $row, MouseAction::WheelDown);
        }

        $up = self::wheelRow($scrolled, $row, MouseAction::WheelUp);

        $screen = self::draw($up);
        self::assertStringContainsString('option10', $screen, 'one notch up from scroll 10 lands on option10');
        self::assertStringNotContainsString('option9 ', $screen, 'not moved further than one notch');
    }

    #[Test]
    public function wheelUpAtTheTopOfTheListStaysAtTheTop(): void
    {
        $opened = self::listOf(self::manyOptions());

        $up = self::wheelRow($opened, self::rowOf($opened, 'option1'), MouseAction::WheelUp);

        self::assertStringContainsString('option1 ', self::draw($up), 'already at the top');
    }

    #[Test]
    public function wheelDownThenWheelUpReturnsToTheStartingScroll(): void
    {
        $opened = self::listOf(self::manyOptions());
        $row = self::rowOf($opened, 'option1');

        $down = self::wheelRow($opened, $row, MouseAction::WheelDown);
        $backUp = self::wheelRow($down, $row, MouseAction::WheelUp);

        self::assertStringContainsString('option1 ', self::draw($backUp), 'one down then one up returns to the top');
    }

    #[Test]
    public function theWheelStopsWithTheLastOptionAtTheBottomOfTheList(): void
    {
        $opened = self::listOf(self::manyOptions());
        $row = self::rowOf($opened, 'option1');

        $scrolled = $opened;
        for ($step = 0; $step < 40; ++$step) {
            $scrolled = self::wheelRow($scrolled, $row, MouseAction::WheelDown);
        }

        $screen = self::draw($scrolled);

        self::assertStringContainsString('option30', $screen, 'the end of the list is reached');
        self::assertStringContainsString('option26', $screen, 'and the window is still full');
    }

    #[Test]
    public function wheelDownDoesNothingWhenTheWholeListAlreadyFits(): void
    {
        $opened = self::listOf(['a', 'b']);
        $row = self::rowOf($opened, 'a');

        $scrolled = self::wheelRow($opened, $row, MouseAction::WheelDown);

        self::assertSame($row, self::rowOf($scrolled, 'a'), 'nothing to scroll: the first option stays on its row');
    }

    #[Test]
    public function theWheelIsIgnoredWhenThePointerIsNotOverTheList(): void
    {
        $opened = self::listOf(self::manyOptions());

        $elsewhere = $opened->update(
            self::routed($opened, new MouseMsg(38, 17, MouseAction::WheelDown, MouseButton::None)),
            self::anchor(),
        )->model;

        self::assertSame($opened, $elsewhere, 'a pane behind the list keeps its own wheel');
    }

    #[Test]
    public function clickingARowPicksWhicheverOptionTheDrainActuallyDrewThere(): void
    {
        $opened = self::opened();

        $clicked = self::clickRow($opened, self::rowOf($opened, 'yaml'));

        self::assertSame('yaml', $clicked->selectedValue(), 'the value picked matches the option drawn on that row');
    }

    #[Test]
    public function aKeyDrivenScrollPositionsAnOptionWhereAFollowingClickStillPicksIt(): void
    {
        $anchor = new Rect(0, 0, 40, 1);
        $screen = Rect::fromSize(60, 14);
        $options = array_map(static fn (int $index): string => 'option' . $index, range(1, 36));

        $dropdown = Dropdown::new($options)->withFocus(true)->update(new KeyMsg(' '), $anchor)->model;
        for ($step = 0; $step < 20; ++$step) {
            $dropdown = $dropdown->update(new KeyMsg(Key::Down, screen: $screen), $anchor)->model;
        }

        $row = self::rowOf($dropdown, 'option21', $anchor, $screen);
        $clicked = $dropdown->update(
            self::routed($dropdown, new MouseMsg(2, $row, MouseAction::Press, MouseButton::Left), $anchor, $screen),
            $anchor,
        )->model;

        self::assertSame('option21', $clicked->selectedValue(), 'the row the keyboard scrolled it into is where a click picks it');
    }

    #[Test]
    public function aClickPicksTheOptionThatWasDrawnThereEvenAfterTheListScrolledOn(): void
    {
        $opened = self::listOf(self::manyOptions());
        $firstOptionRow = self::rowOf($opened, 'option1');

        $staleClick = self::routed($opened, new MouseMsg(2, $firstOptionRow, MouseAction::Press, MouseButton::Left));
        $scrolledOn = self::wheelRow($opened, $firstOptionRow, MouseAction::WheelDown, 5);

        $picked = $scrolledOn->update($staleClick, self::anchor())->model;

        self::assertSame(
            'option1',
            $picked->selectedValue(),
            'the click resolves against the popup that was drawn, never against what now sits on that row',
        );
    }

    #[Test]
    public function aSecondClickAfterThePickIsDiscarded(): void
    {
        $opened = self::opened();
        $firstClick = self::routed($opened, new MouseMsg(2, self::rowOf($opened, 'yaml'), MouseAction::Press, MouseButton::Left));
        $clickOnTheOtherRow = self::routed($opened, new MouseMsg(2, self::rowOf($opened, 'json'), MouseAction::Press, MouseButton::Left));

        $picked = $opened->update($firstClick, self::anchor())->model;
        $again = $picked->update($clickOnTheOtherRow, self::anchor())->model;

        self::assertSame('yaml', $again->selectedValue(), 'the owner is closed, so the second half of a double click dies here');
    }

    #[Test]
    public function anActionThePopupDoesNotCaptureIsIgnoredEvenWhenItArrivesWrapped(): void
    {
        $opened = self::opened();
        $placement = self::drawnPlacement($opened);
        $drawn = self::drawnFloat($opened);

        $wrapped = new FloatMouseMsg(
            new MouseMsg(2, $placement->y + 3, MouseAction::Motion, MouseButton::Left, screen: self::viewport()),
            $drawn,
        );

        $moved = $opened->update($wrapped, self::anchor())->model;

        self::assertNull($moved->selectedValue(), 'only a press picks');
        self::assertTrue($moved->isOpen());
    }

    #[Test]
    public function aFloatMessageFromAnotherDropdownIsDiscarded(): void
    {
        $anchor = self::anchor();
        $mine = self::opened();
        $foreign = self::listOf(['json', 'yaml']);

        $frame = new Frame(40, 18);
        $context = new RenderContext(Theme::dark());
        $context->beginFrame(0.0);
        $context->renderRoot(Overlay::new($mine, $foreign), $frame, $anchor);
        $context->commitFloatPlacements();

        $placements = $context->floatPlacements();
        self::assertCount(2, $placements, 'Overlay hands both dropdowns the same rect, so both popups are drawn');

        $foreignPopup = $placements[1];
        $routed = new FloatMouseMsg(
            new MouseMsg(2, $foreignPopup->placement->y + 3, MouseAction::Press, MouseButton::Left, screen: $frame->area()),
            $foreignPopup,
        );

        $untouched = $mine->update($routed, $anchor)->model;

        self::assertNull($untouched->selectedValue(), 'a token that is not mine is not mine to act on');
        self::assertTrue($untouched->isOpen(), 'and my own popup stays open');
    }

    #[Test]
    public function anOptionThatIsNoLongerOfferedIsNotSelected(): void
    {
        $opened = self::opened();
        $placement = self::drawnPlacement($opened);
        $vanished = DropdownPopup::of(['gone'], [], 0, '', 0, self::drawnTarget($opened));

        $routed = new FloatMouseMsg(
            new MouseMsg(2, $placement->y + 3, MouseAction::Press, MouseButton::Left, screen: self::viewport()),
            new FloatPlacement($vanished, self::anchor(), $placement),
        );

        $clicked = $opened->update($routed, self::anchor())->model;

        self::assertNull($clicked->selectedValue(), 'the drawn value is gone from the options, so nothing is picked');
    }

    #[Test]
    public function aPressCarryingNoButtonPicksNothing(): void
    {
        $opened = self::opened();
        $drawn = self::drawnFloat($opened);

        $wrapped = new FloatMouseMsg(
            new MouseMsg(2, $drawn->placement->y + 3, MouseAction::Press, MouseButton::None, screen: self::viewport()),
            $drawn,
        );

        $clicked = $opened->update($wrapped, self::anchor())->model;

        self::assertNull($clicked->selectedValue(), 'the closed row ignores a button-less press, and so does the popup');
        self::assertTrue($clicked->isOpen());
    }

    #[Test]
    public function aPopupWithNoRoomForOptionsPicksNothingAndStaysOpen(): void
    {
        $anchor = new Rect(0, 4, 20, 1);
        $screen = Rect::fromSize(20, 9);

        $opened = Dropdown::new(['a', 'b'])
            ->withFocus(true)
            ->update(new KeyMsg(' ', screen: $screen), $anchor)->model;

        $frame = new Frame($screen->width, $screen->height);
        $context = new RenderContext(Theme::dark());
        $context->beginFrame(0.0);
        $context->renderRoot($opened, $frame, $anchor);
        $context->commitFloatPlacements();

        $drawn = $context->floatPlacements()[0];
        self::assertSame(0, $drawn->float->optionRowsIn($drawn->placement), 'this popup has chrome only');

        $routed = InputRouter::route(
            new MouseMsg(2, $drawn->placement->y + 3, MouseAction::Press, MouseButton::Left),
            $frame->area(),
            $context->floatPlacements(),
        );
        $clicked = $opened->update($routed, $anchor)->model;

        self::assertNull($clicked->selectedValue(), 'there is no option row to hit');
        self::assertTrue($clicked->isOpen(), 'and the query row stays available');
    }

    #[Test]
    public function aFreshlyOpenedListStartsAtItsFirstOption(): void
    {
        $anchor = self::anchor();
        $screen = Rect::fromSize(20, 12);

        $opened = Dropdown::new(['alpha', 'bravo', 'charlie', 'delta'])
            ->withSelected('bravo')
            ->withFocus(true)
            ->update(new KeyMsg(' ', screen: $screen), $anchor)->model;

        self::assertStringContainsString('alpha', self::drawIn($opened, $screen), 'nothing has scrolled yet');
    }

    #[Test]
    public function reopeningAfterAPickStartsAtTheTopWhenTheSelectionAlreadyFits(): void
    {
        $anchor = self::anchor();
        $screen = Rect::fromSize(20, 12);

        $picked = Dropdown::new(['alpha', 'bravo', 'charlie', 'delta'])
            ->withFocus(true)
            ->update(new KeyMsg(' ', screen: $screen), $anchor)->model
            ->update(new KeyMsg(Key::Down, screen: $screen), $anchor)->model
            ->update(new KeyMsg(Key::Enter, screen: $screen), $anchor)->model;

        self::assertSame('bravo', $picked->selectedValue());

        $reopened = $picked->update(new KeyMsg(' ', screen: $screen), $anchor)->model;

        self::assertStringContainsString(
            'alpha',
            self::drawIn($reopened, $screen),
            'closing must leave no scroll behind for the next open to inherit',
        );
    }

    #[Test]
    public function openingWhereThePopupHasNoRoomForOptionsLeavesTheWindowAtTheTop(): void
    {
        $anchor = self::anchor();
        $tooShortForAnyOption = Rect::fromSize(20, 4);
        $options = array_map(static fn (int $index): string => sprintf('opt%02d', $index), range(1, 20));

        $opened = Dropdown::new($options)
            ->withSelected('opt18')
            ->withFocus(true)
            ->update(new KeyMsg(' ', screen: $tooShortForAnyOption), $anchor)->model;

        $drawn = self::drawIn($opened, Rect::fromSize(20, 16));

        self::assertStringContainsString('opt01', $drawn, 'with no option row to scroll into, the window stays where it is');
        self::assertStringNotContainsString('opt20', $drawn, 'and never past the end of the list');
    }

    #[Test]
    public function theFloatTokenSurvivesEveryWither(): void
    {
        $opened = self::opened();

        $withered = $opened
            ->withLabels(['json' => 'JSON'])
            ->withPlaceholder('pick one')
            ->withFreeText(true)
            ->withSelected('json');

        self::assertSame(self::drawnTarget($opened), self::drawnTarget($withered), 'ownership is one token for the widget lifetime');
    }

    private static function clickRowOf(Dropdown $dropdown, string $option): Dropdown
    {
        return self::clickRow($dropdown, self::rowOf($dropdown, $option));
    }

    private static function clickRow(Dropdown $dropdown, int $y): Dropdown
    {
        return $dropdown->update(
            self::routed($dropdown, new MouseMsg(2, $y, MouseAction::Press, MouseButton::Left)),
            self::anchor(),
        )->model;
    }

    private static function wheelRow(Dropdown $dropdown, int $y, MouseAction $action, int $repeat = 1): Dropdown
    {
        return $dropdown->update(
            self::routed($dropdown, new MouseMsg(2, $y, $action, MouseButton::None, $repeat)),
            self::anchor(),
        )->model;
    }

    /**
     * Renders the dropdown exactly as a runtime would, then routes the event
     * over the floats that render drew.
     */
    private static function routed(Dropdown $dropdown, MouseMsg $mouse, ?Rect $anchor = null, ?Rect $viewport = null): Message
    {
        $viewport ??= self::viewport();
        $frame = new Frame($viewport->width, $viewport->height);
        $context = new RenderContext(Theme::dark());
        $context->beginFrame(0.0);
        $context->renderRoot($dropdown, $frame, $anchor ?? self::anchor());
        $context->commitFloatPlacements();

        return InputRouter::route($mouse, $frame->area(), $context->floatPlacements());
    }

    private static function drawnPlacement(Dropdown $dropdown): Rect
    {
        return self::drawnFloat($dropdown)->placement;
    }

    private static function drawnTarget(Dropdown $dropdown): FloatTarget
    {
        return self::drawnFloat($dropdown)->target();
    }

    private static function drawnFloat(Dropdown $dropdown): FloatPlacement
    {
        $frame = new Frame(40, 18);
        $context = new RenderContext(Theme::dark());
        $context->beginFrame(0.0);
        $context->renderRoot($dropdown, $frame, self::anchor());
        $context->commitFloatPlacements();

        $placements = $context->floatPlacements();

        if ($placements === []) {
            throw new RuntimeException('the dropdown drew no popup');
        }

        return $placements[0];
    }

    private static function rowOf(Dropdown $dropdown, string $needle, ?Rect $anchor = null, ?Rect $viewport = null): int
    {
        $viewport ??= self::viewport();
        $frame = new Frame($viewport->width, $viewport->height);
        (new RenderContext(Theme::dark()))->renderRoot($dropdown, $frame, $anchor ?? self::anchor());
        $drawn = ScreenBuffer::fromFrame($frame);

        for ($row = 0; $row < $frame->height; ++$row) {
            if (str_contains($drawn->getLine($row), $needle)) {
                return $row;
            }
        }

        throw new RuntimeException(sprintf('"%s" was never drawn', $needle));
    }

    private static function draw(Dropdown $dropdown): string
    {
        return self::drawIn($dropdown, self::viewport());
    }

    private static function drawIn(Dropdown $dropdown, Rect $viewport): string
    {
        $frame = new Frame($viewport->width, $viewport->height);
        (new RenderContext(Theme::dark()))->renderRoot($dropdown, $frame, self::anchor());

        return ScreenBuffer::fromFrame($frame)->getPlainText();
    }

    /**
     * @return list<string>
     */
    private static function manyOptions(): array
    {
        return array_map(static fn (int $index): string => 'option' . $index, range(1, 30));
    }

    private static function opened(): Dropdown
    {
        return self::listOf(['json', 'yaml']);
    }

    /**
     * @param list<string> $options
     */
    private static function listOf(array $options): Dropdown
    {
        return Dropdown::new($options)
            ->withFocus(true)
            ->update(new KeyMsg(' '), self::anchor())->model;
    }

    private static function viewport(): Rect
    {
        return Rect::fromSize(40, 18);
    }

    private static function anchor(): Rect
    {
        return new Rect(0, 0, 20, 1);
    }
}
