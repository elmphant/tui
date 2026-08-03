<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseButton;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Testing\ScreenBuffer;
use Elmphant\Tui\Widget\Dropdown;
use Elmphant\Tui\Widget\DropdownPopup;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DropdownMouseTest extends TestCase
{
    #[Test]
    public function clickingAnOptionPicksIt(): void
    {
        $opened = self::opened();
        $firstOptionRow = self::placementOf($opened)->y + DropdownPopup::CHROME;

        $clicked = $opened->update(
            new MouseMsg(2, $firstOptionRow + 1, MouseAction::Press, MouseButton::Left, screen: self::viewport()),
            self::anchor(),
        )->model;

        self::assertSame('yaml', $clicked->selectedValue(), 'The second option row');
        self::assertFalse($clicked->isOpen());
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
    public function theWheelScrollsByAsManyNotchesAsTheEventCarries(): void
    {
        $many = array_map(static fn (int $index): string => 'option' . $index, range(1, 30));
        $opened = self::listOf($many);
        $row = self::placementOf($opened, $many)->y + DropdownPopup::CHROME;

        $scrolled = $opened->update(
            new MouseMsg(2, $row, MouseAction::WheelDown, MouseButton::None, repeat: 3, screen: self::viewport()),
            self::anchor(),
        )->model;

        self::assertStringContainsString(
            'option8',
            self::draw($scrolled),
            'Three notches moved the window three rows, not one',
        );
        self::assertStringNotContainsString(
            'option3 ',
            self::draw($scrolled),
            'A single notch would still show option3; three notches moved past it',
        );
    }

    #[Test]
    public function theWheelStopsWithTheLastOptionAtTheBottomOfTheList(): void
    {
        $many = array_map(static fn (int $index): string => 'option' . $index, range(1, 30));
        $opened = self::listOf($many);
        $row = self::placementOf($opened, $many)->y + DropdownPopup::CHROME;

        $scrolled = $opened;
        for ($step = 0; $step < 40; ++$step) {
            $scrolled = $scrolled->update(
                new MouseMsg(2, $row, MouseAction::WheelDown, MouseButton::None, screen: self::viewport()),
                self::anchor(),
            )->model;
        }

        $screen = self::draw($scrolled);

        self::assertStringContainsString('option30', $screen, 'The end of the list is reached');
        self::assertStringContainsString('option26', $screen, 'and the window is still full, not down to one row');
    }

    #[Test]
    public function clickingTheBottomBorderOfACappedListPicksNothing(): void
    {
        $many = array_map(static fn (int $index): string => 'option' . $index, range(1, 30));
        $opened = self::listOf($many);
        $placement = self::placementOf($opened, $many);

        $clicked = $opened->update(
            new MouseMsg(2, $placement->bottom() - 1, MouseAction::Press, MouseButton::Left, screen: self::viewport()),
            self::anchor(),
        )->model;

        self::assertNull(
            $clicked->selectedValue(),
            'The row below the last visible option is the border, not the next option',
        );
    }

    #[Test]
    public function clickingARowMappedPastTheEndOfAListThatJustShrankPicksNothing(): void
    {
        $anchor = new Rect(0, 0, 20, 1);
        $viewport = Rect::fromSize(20, 8);
        $options = ['a', 'b', 'c', 'd', 'e'];
        $allMatch = ['a' => 'zzz-a', 'b' => 'zzz-b', 'c' => 'zzz-c', 'd' => 'zzz-d', 'e' => 'zzz-e'];

        $typed = Dropdown::new($options)
            ->withLabels($allMatch)
            ->withFocus(true)
            ->update(new KeyMsg(' '), $anchor)->model
            ->update(new KeyMsg('z'), $anchor)->model
            ->update(new KeyMsg('z'), $anchor)->model
            ->update(new KeyMsg('z'), $anchor)->model;

        $fiveItemRow = DropdownPopup::of($options, $allMatch, 0, 'zzz', 0)
            ->placement($anchor, $viewport)->y + DropdownPopup::CHROME;

        // Scroll to 1 while all 5 options still match "zzz". Nothing but the
        // wheel touches scroll, so it survives the relabeling below untouched.
        $scrolled = $typed->update(
            new MouseMsg(2, $fiveItemRow, MouseAction::WheelDown, MouseButton::None, screen: $viewport),
            $anchor,
        )->model;

        // Shrink the match set to 2 without any key or mouse event that would
        // reset scroll — the popup is now sized for 2 options, but scroll is
        // still the stale 1 left over from when there were 5.
        $twoMatch = [
            'a' => 'zzz-a',
            'b' => 'zzz-b',
            'c' => 'no-match-c',
            'd' => 'no-match-d',
            'e' => 'no-match-e',
        ];
        $shrunk = $scrolled->withLabels($twoMatch);

        $newPlacement = DropdownPopup::of(['a', 'b'], $twoMatch, 0, 'zzz', 1)->placement($anchor, $viewport);
        $rowPastTheShrunkList = $newPlacement->y + DropdownPopup::CHROME + 1;

        $clicked = $shrunk->update(
            new MouseMsg(2, $rowPastTheShrunkList, MouseAction::Press, MouseButton::Left, screen: $viewport),
            $anchor,
        )->model;

        $picked = $clicked->update(new KeyMsg(Key::Enter), $anchor)->model;

        self::assertSame(
            'a',
            $picked->selectedValue(),
            'The click maps to an index past the shrunk list and must be rejected, leaving the cursor untouched',
        );
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

        self::assertFalse($clicked->isOpen(), 'Dismissing never needed the geometry');
        self::assertNull($clicked->selectedValue());
    }

    #[Test]
    public function theWheelIsIgnoredWhenThePointerIsNotOverTheList(): void
    {
        $many = array_map(static fn (int $index): string => 'option' . $index, range(1, 30));
        $opened = self::listOf($many);

        $elsewhere = $opened->update(
            new MouseMsg(38, 9, MouseAction::WheelDown, MouseButton::None, screen: self::viewport()),
            self::anchor(),
        )->model;

        self::assertSame($opened, $elsewhere, 'A pane behind the list keeps its own wheel');
    }

    #[Test]
    public function clickingExactlyTheFirstOptionRowPicksTheFirstOption(): void
    {
        $opened = self::opened();
        $firstOptionRow = self::placementOf($opened)->y + DropdownPopup::CHROME;

        $clicked = $opened->update(
            new MouseMsg(2, $firstOptionRow, MouseAction::Press, MouseButton::Left, screen: self::viewport()),
            self::anchor(),
        )->model;

        self::assertSame('json', $clicked->selectedValue(), 'The first option row itself must be clickable, not just the ones after it');
    }

    #[Test]
    public function anUnhandledMouseActionInsideTheListIsIgnoredWithoutError(): void
    {
        $opened = self::opened();
        $row = self::placementOf($opened)->y + DropdownPopup::CHROME;

        $result = $opened->update(
            new MouseMsg(2, $row, MouseAction::Motion, MouseButton::None, screen: self::viewport()),
            self::anchor(),
        )->model;

        self::assertTrue($result->isOpen(), 'An action matching none of the arms must fall through, not throw');
        self::assertNull($result->selectedValue());
    }

    #[Test]
    public function wheelDownScrollsDownByExactlyOneNotch(): void
    {
        $many = array_map(static fn (int $index): string => 'option' . $index, range(1, 30));
        $opened = self::listOf($many);
        $row = self::placementOf($opened, $many)->y + DropdownPopup::CHROME;

        $scrolled = $opened->update(
            new MouseMsg(2, $row, MouseAction::WheelDown, MouseButton::None, screen: self::viewport()),
            self::anchor(),
        )->model;

        $screen = self::draw($scrolled);
        self::assertStringContainsString('option2 ', $screen, 'Scrolled by exactly one notch, option2 is now first');
        self::assertStringNotContainsString('option1 ', $screen, 'option1 has scrolled just out of view');
    }

    #[Test]
    public function wheelUpScrollsUpByExactlyOneNotch(): void
    {
        $many = array_map(static fn (int $index): string => 'option' . $index, range(1, 30));
        $opened = self::listOf($many);
        $row = self::placementOf($opened, $many)->y + DropdownPopup::CHROME;

        $scrolled = $opened;
        for ($step = 0; $step < 10; ++$step) {
            $scrolled = $scrolled->update(
                new MouseMsg(2, $row, MouseAction::WheelDown, MouseButton::None, screen: self::viewport()),
                self::anchor(),
            )->model;
        }

        $up = $scrolled->update(
            new MouseMsg(2, $row, MouseAction::WheelUp, MouseButton::None, screen: self::viewport()),
            self::anchor(),
        )->model;

        $screen = self::draw($up);
        self::assertStringContainsString('option10', $screen, 'One notch up from scroll 10 lands on option10');
        self::assertStringNotContainsString('option9 ', $screen, 'Not moved further than one notch');
    }

    #[Test]
    public function wheelUpAtTheTopOfTheListStaysAtTheTop(): void
    {
        $many = array_map(static fn (int $index): string => 'option' . $index, range(1, 30));
        $opened = self::listOf($many);
        $row = self::placementOf($opened, $many)->y + DropdownPopup::CHROME;

        $up = $opened->update(
            new MouseMsg(2, $row, MouseAction::WheelUp, MouseButton::None, screen: self::viewport()),
            self::anchor(),
        )->model;

        self::assertStringContainsString('option1 ', self::draw($up), 'Already at the top, wheel up must not move past it');
    }

    #[Test]
    public function wheelDownThenWheelUpReturnsToTheStartingScroll(): void
    {
        $many = array_map(static fn (int $index): string => 'option' . $index, range(1, 30));
        $opened = self::listOf($many);
        $row = self::placementOf($opened, $many)->y + DropdownPopup::CHROME;

        $down = $opened->update(
            new MouseMsg(2, $row, MouseAction::WheelDown, MouseButton::None, screen: self::viewport()),
            self::anchor(),
        )->model;
        $backUp = $down->update(
            new MouseMsg(2, $row, MouseAction::WheelUp, MouseButton::None, screen: self::viewport()),
            self::anchor(),
        )->model;

        self::assertStringContainsString('option1 ', self::draw($backUp), 'One down then one up returns exactly to the top');
    }

    #[Test]
    public function wheelDownDoesNothingWhenTheWholeListAlreadyFits(): void
    {
        $opened = self::listOf(['a', 'b']);
        $placement = self::placementOf($opened, ['a', 'b']);
        $row = $placement->y + DropdownPopup::CHROME;

        $scrolled = $opened->update(
            new MouseMsg(2, $row, MouseAction::WheelDown, MouseButton::None, screen: self::viewport()),
            self::anchor(),
        )->model;

        $frame = new Frame(40, 10);
        (new RenderContext(Theme::dark()))->renderRoot($scrolled, $frame, self::anchor());

        self::assertStringContainsString(
            'a',
            ScreenBuffer::fromFrame($frame)->getTextAt($row, 0, 10),
            'Nothing to scroll: the first option must remain on its row',
        );
    }

    #[Test]
    public function clickingTheFilterRowPicksNothing(): void
    {
        $many = array_map(static fn (int $index): string => 'option' . $index, range(1, 30));
        $opened = self::listOf($many);
        $placement = self::placementOf($opened, $many);
        $row = $placement->y + DropdownPopup::CHROME;

        $scrolled = $opened->update(
            new MouseMsg(2, $row, MouseAction::WheelDown, MouseButton::None, repeat: 5, screen: self::viewport()),
            self::anchor(),
        )->model;

        $onFilterRow = $scrolled->update(
            new MouseMsg(2, $placement->y + 1, MouseAction::Press, MouseButton::Left, screen: self::viewport()),
            self::anchor(),
        )->model;

        self::assertNull(
            $onFilterRow->selectedValue(),
            'Scrolled 5 rows in, the query row must not map through the scroll offset into a real option',
        );
        self::assertTrue($onFilterRow->isOpen(), 'and clicking inside the popup does not dismiss it');
    }

    #[Test]
    public function clickingARowPicksWhicheverOptionTheDrainActuallyDrewThere(): void
    {
        $anchor = self::anchor();
        $opened = Dropdown::new(['json', 'yaml'])
            ->withFocus(true)
            ->update(new KeyMsg(' '), $anchor)->model;

        $frame = new Frame(40, 10);
        (new RenderContext(Theme::dark()))->renderRoot($opened, $frame, $anchor);
        $drawn = ScreenBuffer::fromFrame($frame);
        $row = self::rowContaining($drawn, 'yaml');

        $clicked = $opened->update(
            new MouseMsg(2, $row, MouseAction::Press, MouseButton::Left, screen: $frame->area()),
            $anchor,
        )->model;

        self::assertSame('yaml', $clicked->selectedValue(), 'The value picked must match the option drawn on the clicked row');
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

        $frame = new Frame(60, 14);
        (new RenderContext(Theme::dark()))->renderRoot($dropdown, $frame, $anchor);
        $drawn = ScreenBuffer::fromFrame($frame);
        $row = self::rowContaining($drawn, 'option21', $frame->height);

        $clicked = $dropdown->update(
            new MouseMsg(2, $row, MouseAction::Press, MouseButton::Left, screen: $frame->area()),
            $anchor,
        )->model;

        self::assertSame(
            'option21',
            $clicked->selectedValue(),
            'The row the keyboard scrolled option21 into must be exactly where a click picks it',
        );
    }

    private static function rowContaining(ScreenBuffer $drawn, string $needle, int $height = 10): int
    {
        for ($row = 0; $row < $height; ++$row) {
            if (str_contains($drawn->getLine($row), $needle)) {
                return $row;
            }
        }

        throw new RuntimeException(sprintf('"%s" was never drawn', $needle));
    }

    private static function draw(Dropdown $dropdown): string
    {
        $frame = new Frame(40, 10);
        $context = new RenderContext(Theme::dark());
        $context->renderRoot($dropdown, $frame, self::anchor());

        return ScreenBuffer::fromFrame($frame)->getPlainText();
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

    /**
     * @param list<string> $options
     */
    private static function placementOf(Dropdown $dropdown, array $options = ['json', 'yaml']): Rect
    {
        return DropdownPopup::of($options, [], 0, '', 0)->placement(self::anchor(), self::viewport());
    }

    private static function viewport(): Rect
    {
        return Rect::fromSize(40, 10);
    }

    private static function anchor(): Rect
    {
        return new Rect(0, 0, 20, 1);
    }
}
