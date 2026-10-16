<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseButton;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Rendering\Context\FloatMouseMsg;
use Elmphant\Tui\Rendering\Context\FloatPlacement;
use Elmphant\Tui\Rendering\Context\FloatTarget;
use Elmphant\Tui\Rendering\Context\FocusPath;
use Elmphant\Tui\Rendering\Context\RenderPass;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Runtime\InputRouter;
use Elmphant\Tui\Testing\ScreenBuffer;
use Elmphant\Tui\Tests\Support\RootPass;
use Elmphant\Tui\Widget\Choice\Select;
use Elmphant\Tui\Widget\Popup\Dropdown;
use Elmphant\Tui\Widget\Popup\DropdownPopup;
use Elmphant\Tui\Widget\Popup\Overlay;
use Elmphant\Tui\Widget\Widget;
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

        self::assertSame('yaml', $clicked->content()->selectedValue());
        self::assertFalse($clicked->isOpen());
    }

    #[Test]
    public function clickingExactlyTheFirstOptionRowPicksTheFirstOption(): void
    {
        $clicked = self::clickRowOf(self::opened(), 'json');

        self::assertSame('json', $clicked->content()->selectedValue(), 'the first option row itself must be clickable');
    }

    #[Test]
    public function clickingTheClosedRowOpensItOnTheSelectionScrolledIntoView(): void
    {
        $anchor = new Rect(0, 0, 20, 1);
        $screen = Rect::fromSize(20, 8);
        $options = array_map(static fn (int $index): string => 'option' . $index, range(1, 20));

        $dropdown = Dropdown::new(Select::new($options)->activated('option18'));

        $clicked = $dropdown->update(
            new MouseMsg(2, 0, MouseAction::Press, MouseButton::Left, screen: $screen),
            $anchor,
        )->model;

        self::assertTrue($clicked->isOpen(), 'a click on the closed row opens the popup');

        $frame = new Frame(20, 8);
        self::renderFocused($clicked, $frame, $anchor);

        $drawn = [];
        for ($row = 1; $row < $frame->height; ++$row) {
            $drawn[] = ScreenBuffer::fromFrame($frame)->lineAt($row);
        }

        self::assertStringContainsString('option18', implode("\n", $drawn), 'it opens on the selection, scrolled into view');
    }

    #[Test]
    public function clickingAnUnfocusedClosedRowOpensIt(): void
    {
        $unfocused = Dropdown::new(Select::new(['json', 'yaml']));

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
        $dropdown = Dropdown::new(Select::new(['json', 'yaml']));

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
        self::assertNull($clicked->content()->selectedValue());
    }

    #[Test]
    public function clickingOutsideTheListClosesItWithoutPicking(): void
    {
        $clicked = self::opened()->update(
            new MouseMsg(2, 9, MouseAction::Press, MouseButton::Left, screen: self::viewport()),
            self::anchor(),
        )->model;

        self::assertNull($clicked->content()->selectedValue());
        self::assertFalse($clicked->isOpen());
    }

    #[Test]
    public function clickingAwayCancelsEvenWithoutAScreenRectOnTheMessage(): void
    {
        $blind = Dropdown::new(Select::new(['json', 'yaml']))
                        ->update(new KeyMsg(' '), self::anchor())->model;

        $clicked = $blind->update(
            new MouseMsg(2, 9, MouseAction::Press, MouseButton::Left),
            self::anchor(),
        )->model;

        self::assertFalse($clicked->isOpen(), 'dismissing never needed the geometry');
        self::assertNull($clicked->content()->selectedValue());
    }

    #[Test]
    public function clickingTheBottomBorderOfACappedListPicksNothing(): void
    {
        $opened = self::listOf(self::manyOptions());
        $placement = self::drawnPlacement($opened);

        $clicked = self::clickRow($opened, $placement->bottom() - 1);

        self::assertNull($clicked->content()->selectedValue(), 'the row below the last visible option is the border');
    }

    #[Test]
    public function aClickInsideThePopupThatNamesNoOptionLeavesItOpen(): void
    {
        $opened = self::listOf(self::manyOptions());
        $scrolled = self::wheelRow($opened, self::rowOf($opened, 'option1'), MouseAction::WheelDown, 5);
        $placement = self::drawnPlacement($scrolled);

        $onTheBorder = self::clickRow($scrolled, $placement->y);

        self::assertNull($onTheBorder->content()->selectedValue(), 'the border is not an option row however far the list has moved');
        self::assertTrue($onTheBorder->isOpen(), 'and a click that chose nothing is not a click that dismisses');
    }

    #[Test]
    public function clickingARowPastTheEndOfADrawnListPicksNothing(): void
    {
        $opened = self::listOf(['a', 'b']);
        $lastOptionRow = self::rowOf($opened, 'b');

        $clicked = self::clickRow($opened, $lastOptionRow + 1);

        self::assertNull($clicked->content()->selectedValue(), 'no option was drawn there');
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
        self::assertNull($result->content()->selectedValue());
    }

    #[Test]
    public function theWheelScrollsByAsManyNotchesAsTheEventCarries(): void
    {
        $opened = self::listOf(self::manyOptions());

        $scrolled = self::wheelRow($opened, self::rowOf($opened, 'option1'), MouseAction::WheelDown, 3);

        self::assertSame(3, $scrolled->content()->cursor(), 'a merged event carries all of its notches');
    }

    #[Test]
    public function wheelDownScrollsDownByExactlyOneNotch(): void
    {
        $opened = self::listOf(self::manyOptions());

        $scrolled = self::wheelRow($opened, self::rowOf($opened, 'option1'), MouseAction::WheelDown);

        self::assertSame(1, $scrolled->content()->cursor(), 'one notch, one option');
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

        self::assertSame(9, $up->content()->cursor(), 'one notch back from ten, and no further');
        self::assertStringContainsString('option10', self::draw($up), 'and the view came with it');
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

        self::assertSame('yaml', $clicked->content()->selectedValue(), 'the value picked matches the option drawn on that row');
    }

    #[Test]
    public function aKeyDrivenScrollPositionsAnOptionWhereAFollowingClickStillPicksIt(): void
    {
        $anchor = new Rect(0, 0, 40, 1);
        $screen = Rect::fromSize(60, 14);
        $options = array_map(static fn (int $index): string => 'option' . $index, range(1, 36));

        $dropdown = Dropdown::new(Select::new($options))->update(new KeyMsg(' '), $anchor)->model;
        for ($step = 0; $step < 20; ++$step) {
            $dropdown = $dropdown->update(new KeyMsg(Key::Down, screen: $screen), $anchor)->model;
        }

        $row = self::rowOf($dropdown, 'option21', $anchor, $screen);
        $clicked = $dropdown->update(
            self::routed($dropdown, new MouseMsg(2, $row, MouseAction::Press, MouseButton::Left), $anchor, $screen),
            $anchor,
        )->model;

        self::assertSame('option21', $clicked->content()->selectedValue(), 'the row the keyboard scrolled it into is where a click picks it');
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
            $picked->content()->selectedValue(),
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

        self::assertSame('yaml', $again->content()->selectedValue(), 'the owner is closed, so the second half of a double click dies here');
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

        self::assertNull($moved->content()->selectedValue(), 'only a press picks');
        self::assertTrue($moved->isOpen());
    }

    #[Test]
    public function aFloatMessageFromAnotherDropdownIsDiscarded(): void
    {
        $anchor = self::anchor();
        $mine = self::opened();
        $foreign = self::listOf(['json', 'yaml']);

        $frame = new Frame(40, 18);
        $pass = self::renderFocused(Overlay::new($mine, $foreign), $frame, $anchor);

        $placements = $pass->floats->placements();
        self::assertCount(2, $placements, 'Overlay hands both dropdowns the same rect, so both popups are drawn');

        $foreignPopup = $placements[1];
        $routed = new FloatMouseMsg(
            new MouseMsg(2, $foreignPopup->placement->y + 3, MouseAction::Press, MouseButton::Left, screen: $frame->area()),
            $foreignPopup,
        );

        $untouched = $mine->update($routed, $anchor)->model;

        self::assertNull($untouched->content()->selectedValue(), 'a token that is not mine is not mine to act on');
        self::assertTrue($untouched->isOpen(), 'and my own popup stays open');
    }

    #[Test]
    public function anOptionThatIsNoLongerOfferedIsNotSelected(): void
    {
        $opened = self::opened();
        $placement = self::drawnPlacement($opened);
        $vanished = DropdownPopup::of(Select::new(['gone']), self::drawnTarget($opened));

        $routed = new FloatMouseMsg(
            new MouseMsg(2, $placement->y + 3, MouseAction::Press, MouseButton::Left, screen: self::viewport()),
            new FloatPlacement($vanished, self::anchor(), $placement),
        );

        $clicked = $opened->update($routed, self::anchor())->model;

        self::assertNull($clicked->content()->selectedValue(), 'the drawn value is gone from the options, so nothing is picked');
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

        self::assertNull($clicked->content()->selectedValue(), 'the closed row ignores a button-less press, and so does the popup');
        self::assertTrue($clicked->isOpen());
    }

    /**
     * The narrowest popup that draws at all is MIN_SIDE rows, of which the
     * border takes two — so a drawn popup always has at least one row for its
     * content, and a shorter one is placed empty and never drawn. The state
     * this once guarded, chrome with no room for an option, cannot arise now
     * that the filter row and separator belong to the content.
     */
    #[Test]
    public function aPopupTooShortForEvenOneContentRowIsNeverDrawn(): void
    {
        $anchor = new Rect(0, 1, 20, 1);
        $popup = DropdownPopup::of(Select::new(['a', 'b']), new FloatTarget());

        self::assertSame(
            0,
            $popup->placement($anchor, Rect::fromSize(20, 3))->height,
            'a single row either side leaves nothing to draw',
        );
        self::assertGreaterThanOrEqual(
            1,
            $popup->contentArea($popup->placement($anchor, Rect::fromSize(20, 9)))->height,
            'and any popup that does draw has a row for its content',
        );
    }

    #[Test]
    public function aFreshlyOpenedListStartsAtItsFirstOption(): void
    {
        $anchor = self::anchor();
        $screen = Rect::fromSize(20, 12);

        $opened = Dropdown::new(Select::new(['alpha', 'bravo', 'charlie', 'delta'])->activated('bravo'))
                        ->update(new KeyMsg(' ', screen: $screen), $anchor)->model;

        self::assertStringContainsString('alpha', self::drawnOptionRows($opened, $screen), 'nothing has scrolled yet');
    }

    #[Test]
    public function reopeningAfterAPickStartsAtTheTopWhenTheSelectionAlreadyFits(): void
    {
        $anchor = self::anchor();
        $screen = Rect::fromSize(20, 12);

        $picked = Dropdown::new(Select::new(['alpha', 'bravo', 'charlie', 'delta']))
                        ->update(new KeyMsg(' ', screen: $screen), $anchor)->model
            ->update(new KeyMsg(Key::Down, screen: $screen), $anchor)->model
            ->update(new KeyMsg(Key::Enter, screen: $screen), $anchor)->model;

        self::assertSame('bravo', $picked->content()->selectedValue());

        $reopened = $picked->update(new KeyMsg(' ', screen: $screen), $anchor)->model;

        self::assertStringContainsString(
            'alpha',
            self::drawnOptionRows($reopened, $screen),
            'closing must leave no scroll behind for the next open to inherit',
        );
    }

    #[Test]
    public function reopeningOnTheFirstOptionStartsAtTheFirstOption(): void
    {
        $anchor = self::anchor();
        $screen = Rect::fromSize(20, 12);

        $picked = Dropdown::new(Select::new(['alpha', 'bravo', 'charlie', 'delta']))
                        ->update(new KeyMsg(' ', screen: $screen), $anchor)->model
            ->update(new KeyMsg(Key::Enter, screen: $screen), $anchor)->model;

        self::assertSame('alpha', $picked->content()->selectedValue());

        $reopened = $picked->update(new KeyMsg(' ', screen: $screen), $anchor)->model;

        self::assertStringContainsString(
            'alpha',
            self::drawnOptionRows($reopened, $screen),
            'a window offset must never count backwards from the end of the list',
        );
    }

    /**
     * The window this once guarded against getting stuck is gone: the content
     * derives its offset from its cursor and the room it is given, every frame,
     * so opening in a popup too short to show the selection cannot leave a
     * stale offset behind to catch up later.
     */
    #[Test]
    public function openingOnAFarSelectionScrollsItIntoViewWithoutRunningPastTheEnd(): void
    {
        $options = array_map(static fn (int $index): string => sprintf('opt%02d', $index), range(1, 20));

        $opened = Dropdown::new(Select::new($options)->activated('opt18'))
                        ->update(new KeyMsg(' ', screen: Rect::fromSize(20, 4)), self::anchor())->model;

        $drawn = self::drawIn($opened, Rect::fromSize(20, 16));

        self::assertStringContainsString('opt18', $drawn, 'the selection is what the reader needs to see');
        self::assertStringNotContainsString('opt20', $drawn, 'and the window never counts past the end of the list');
    }

    #[Test]
    public function theFloatTokenSurvivesEveryWither(): void
    {
        $opened = self::opened();

        $withered = $opened
                        ->withGlobalKeybindings(new Keybindings([Actions::CANCEL => ['q']]));

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
        $pass = self::renderFocused($dropdown, $frame, $anchor ?? self::anchor());

        return InputRouter::route($mouse, $frame->area(), $pass->floats->placements());
    }

    private static function renderFocused(Widget $root, Frame $frame, Rect $anchor): RenderPass
    {
        $pass = RenderPass::new(Theme::dark(), focus: FocusPath::nowhere());
        $pass->begin(0.0);
        $pass->context->withFocusHere(static function () use ($pass, $root, $frame, $anchor): void {
            RootPass::draw($pass, $root, $frame, $anchor);
        });
        $pass->floats->commit();

        return $pass;
    }

    private static function drawnPlacement(Dropdown $dropdown): Rect
    {
        return self::drawnFloat($dropdown)->placement;
    }

    /**
     * Only the option rows of the drawn popup, so an assertion cannot be
     * satisfied by the closed row above it.
     */
    private static function drawnOptionRows(Dropdown $dropdown, Rect $viewport): string
    {
        $frame = new Frame($viewport->width, $viewport->height);
        $pass = self::renderFocused($dropdown, $frame, self::anchor());

        $placements = $pass->floats->placements();

        if ($placements === []) {
            throw new RuntimeException('the dropdown drew no popup');
        }

        $popup = $placements[0]->float;
        self::assertInstanceOf(DropdownPopup::class, $popup);

        $screen = ScreenBuffer::fromFrame($frame);
        $inner = $popup->contentArea($placements[0]->placement);
        $rows = [];

        for ($offset = 0; $offset < $inner->height; ++$offset) {
            $rows[] = $screen->lineAt($inner->y + $offset);
        }

        return implode("\n", $rows);
    }

    private static function drawnTarget(Dropdown $dropdown): FloatTarget
    {
        return self::drawnFloat($dropdown)->target();
    }

    private static function drawnFloat(Dropdown $dropdown): FloatPlacement
    {
        $frame = new Frame(40, 18);
        $pass = self::renderFocused($dropdown, $frame, self::anchor());

        $placements = $pass->floats->placements();

        if ($placements === []) {
            throw new RuntimeException('the dropdown drew no popup');
        }

        return $placements[0];
    }

    private static function rowOf(Dropdown $dropdown, string $needle, ?Rect $anchor = null, ?Rect $viewport = null): int
    {
        $viewport ??= self::viewport();
        $frame = new Frame($viewport->width, $viewport->height);
        self::renderFocused($dropdown, $frame, $anchor ?? self::anchor());
        $drawn = ScreenBuffer::fromFrame($frame);

        for ($row = 0; $row < $frame->height; ++$row) {
            if (str_contains($drawn->lineAt($row), $needle)) {
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
        self::renderFocused($dropdown, $frame, self::anchor());

        return ScreenBuffer::fromFrame($frame)->plainText();
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
        return Dropdown::new(Select::new($options))
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
