<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Rect;
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

final class DropdownMouseTest extends TestCase
{
    #[Test]
    public function clickingAnOptionPicksIt(): void
    {
        $opened = self::opened();
        $firstOptionRow = self::placementOf($opened)->y + DropdownPopup::CHROME;

        $clicked = $opened->update(
            new MouseMsg(2, $firstOptionRow + 1, MouseAction::Press, MouseButton::Left),
            self::anchor(),
        )->model;

        self::assertSame('yaml', $clicked->selectedValue(), 'The second option row');
        self::assertFalse($clicked->isOpen());
    }

    #[Test]
    public function clickingOutsideTheListClosesItWithoutPicking(): void
    {
        $clicked = self::opened()->update(
            new MouseMsg(2, 9, MouseAction::Press, MouseButton::Left),
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
            new MouseMsg(2, $row, MouseAction::WheelDown, MouseButton::None, repeat: 3),
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
                new MouseMsg(2, $row, MouseAction::WheelDown, MouseButton::None),
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
            new MouseMsg(2, $placement->bottom() - 1, MouseAction::Press, MouseButton::Left),
            self::anchor(),
        )->model;

        self::assertNull(
            $clicked->selectedValue(),
            'The row below the last visible option is the border, not the next option',
        );
    }

    #[Test]
    public function clickingAwayCancelsEvenWithoutAViewport(): void
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
            new MouseMsg(38, 9, MouseAction::WheelDown, MouseButton::None),
            self::anchor(),
        )->model;

        self::assertSame($opened, $elsewhere, 'A pane behind the list keeps its own wheel');
    }

    #[Test]
    public function clickingTheFilterRowPicksNothing(): void
    {
        $many = array_map(static fn (int $index): string => 'option' . $index, range(1, 30));
        $opened = self::listOf($many);
        $placement = self::placementOf($opened, $many);
        $row = $placement->y + DropdownPopup::CHROME;

        $scrolled = $opened->update(
            new MouseMsg(2, $row, MouseAction::WheelDown, MouseButton::None, repeat: 5),
            self::anchor(),
        )->model;

        $onFilterRow = $scrolled->update(
            new MouseMsg(2, $placement->y + 1, MouseAction::Press, MouseButton::Left),
            self::anchor(),
        )->model;

        self::assertNull(
            $onFilterRow->selectedValue(),
            'Scrolled 5 rows in, the query row must not map through the scroll offset into a real option',
        );
        self::assertTrue($onFilterRow->isOpen(), 'and clicking inside the popup does not dismiss it');
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
            ->withViewport(self::viewport())
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
