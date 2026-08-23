<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseButton;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Runtime\InputRouter;
use Elmphant\Tui\Testing\ScreenBuffer;
use Elmphant\Tui\Widget\Dispatch\ScrollPane;
use Elmphant\Tui\Widget\Dropdown;
use Elmphant\Tui\Widget\DropdownPopup;
use Elmphant\Tui\Widget\Select;
use Elmphant\Tui\Widget\Text;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The popup overlaps its siblings' rows, so every one of these clicks would
 * have reached the widget underneath before input routed over drawn floats.
 */
final class DropdownHostAcceptanceTest extends TestCase
{
    #[Test]
    public function clickingAnOptionDrawnOverASiblingPicksItAndLeavesTheSiblingClosed(): void
    {
        $pane = self::paneWithTwoDropdowns(openFirst: true);
        $row = self::rowOf($pane, 'yaml');

        $picked = self::deliver($pane, new MouseMsg(2, $row, MouseAction::Press, MouseButton::Left));

        self::assertSame('yaml', self::dropdownAt($picked, 0)->content()->selectedValue(), 'the popup owns the cell it drew on');
        self::assertNull(self::dropdownAt($picked, 1)->content()->selectedValue(), 'the sibling beneath the popup saw nothing');
        self::assertFalse(self::dropdownAt($picked, 1)->isOpen());
        self::assertTrue(self::dropdownAt($picked, 0)->isFocused(), 'the picker keeps focus');
    }

    #[Test]
    public function theWheelOverThePopupScrollsThePopupAndNotThePaneBeneathIt(): void
    {
        $pane = self::paneWithManyRows(openFirst: true);
        $row = self::rowOf($pane, 'option1');
        $paneOffsetBefore = self::scrollOffsetOf($pane);

        $scrolled = self::deliver($pane, new MouseMsg(2, $row, MouseAction::WheelDown, MouseButton::None, 3));

        self::assertSame($paneOffsetBefore, self::scrollOffsetOf($scrolled), 'the pane must not move');
        self::assertStringContainsString('option4', self::draw($scrolled), 'the popup scrolled by three notches');
    }

    #[Test]
    public function aDragOverThePopupStillReachesTheWidgetBeneath(): void
    {
        $pane = self::paneWithTwoDropdowns(openFirst: true);
        $row = self::rowOf($pane, 'yaml');

        $routed = self::route($pane, new MouseMsg(2, $row, MouseAction::Release, MouseButton::Left));

        self::assertInstanceOf(MouseMsg::class, $routed, 'a Release must pass beneath so a Split divider cannot latch');
    }

    #[Test]
    public function clickingAClosedDropdownInsideAPaneOpensIt(): void
    {
        $pane = self::paneWithTwoDropdowns(openFirst: false);

        $clicked = self::deliver($pane, new MouseMsg(2, 0, MouseAction::Press, MouseButton::Left));

        self::assertTrue(self::dropdownAt($clicked, 0)->isOpen(), 'one click is enough to open it');
    }

    #[Test]
    public function anOptionIsPickableOnTheVeryFirstFrameAfterStartup(): void
    {
        $pane = self::paneWithTwoDropdowns(openFirst: true);

        $firstFrame = new Frame(40, 18);
        $context = new RenderContext(Theme::dark());
        $context->beginFrame(0.0);
        $context->renderRoot($pane, $firstFrame, $firstFrame->area());
        $context->commitFloatPlacements();

        $placements = $context->floatPlacements();
        self::assertCount(1, $placements, 'the popup is on the first frame, drawn through the fallback layout path');

        $popup = $placements[0]->float;
        self::assertInstanceOf(DropdownPopup::class, $popup);

        $routed = InputRouter::route(
            new MouseMsg(2, $popup->contentArea($placements[0]->placement)->y, MouseAction::Press, MouseButton::Left),
            $firstFrame->area(),
            $placements,
        );
        $picked = $pane->update($routed, $firstFrame->area())->model;

        self::assertSame('json', self::dropdownAt($picked, 0)->content()->selectedValue());
    }

    #[Test]
    public function theKeyboardStillWorksAfterAPickedOption(): void
    {
        $pane = self::paneWithTwoDropdowns(openFirst: true);
        $row = self::rowOf($pane, 'yaml');

        $picked = self::deliver($pane, new MouseMsg(2, $row, MouseAction::Press, MouseButton::Left));

        self::assertFalse(self::dropdownAt($picked, 0)->isOpen(), 'the pick closed the popup');

        $reopened = $picked->update(new KeyMsg(' ', screen: self::area()), self::area())->model;

        self::assertTrue(self::dropdownAt($reopened, 0)->isOpen(), 'space still reaches the focused dropdown');
    }

    private static function deliver(ScrollPane $pane, MouseMsg $mouse): ScrollPane
    {
        $result = $pane->update(self::route($pane, $mouse), self::area())->model;

        if (!$result instanceof ScrollPane) {
            throw new RuntimeException('the pane must stay a pane');
        }

        return $result;
    }

    private static function route(ScrollPane $pane, MouseMsg $mouse): Message
    {
        $frame = new Frame(40, 18);
        $context = new RenderContext(Theme::dark());
        $context->beginFrame(0.0);
        $context->renderRoot($pane, $frame, $frame->area());
        $context->commitFloatPlacements();

        return InputRouter::route($mouse, $frame->area(), $context->floatPlacements());
    }

    private static function paneWithTwoDropdowns(bool $openFirst): ScrollPane
    {
        $first = Dropdown::new(Select::new(['json', 'yaml']))->withFocus(true);
        $pane = ScrollPane::of($first, Dropdown::new(Select::new(['toml', 'ini'])), Text::new('beneath'))->withFocus(true);

        return $openFirst
            ? self::openedPane($pane)
            : $pane;
    }

    private static function paneWithManyRows(bool $openFirst): ScrollPane
    {
        $options = array_map(static fn (int $index): string => 'option' . $index, range(1, 30));
        $pane = ScrollPane::of(
            Dropdown::new(Select::new($options))->withFocus(true),
            ...array_map(static fn (int $row): Text => Text::new('row' . $row), range(1, 20)),
        )->withFocus(true);

        return $openFirst
            ? self::openedPane($pane)
            : $pane;
    }

    private static function openedPane(ScrollPane $pane): ScrollPane
    {
        $opened = $pane->update(new KeyMsg(' ', screen: self::area()), self::area())->model;

        if (!$opened instanceof ScrollPane) {
            throw new RuntimeException('the pane must stay a pane');
        }

        return $opened;
    }

    private static function dropdownAt(ScrollPane $pane, int $index): Dropdown
    {
        $children = iterator_to_array($pane->children(), false);
        $child = $children[$index] ?? null;

        if (!$child instanceof Dropdown) {
            throw new RuntimeException(sprintf('child %d is not a Dropdown', $index));
        }

        return $child;
    }

    private static function scrollOffsetOf(ScrollPane $pane): int
    {
        return $pane->scrollOffset();
    }

    private static function rowOf(ScrollPane $pane, string $needle): int
    {
        $drawn = ScreenBuffer::fromFrame(self::rendered($pane));

        for ($row = 0; $row < 18; ++$row) {
            if (str_contains($drawn->lineAt($row), $needle)) {
                return $row;
            }
        }

        throw new RuntimeException(sprintf('"%s" was never drawn', $needle));
    }

    private static function draw(ScrollPane $pane): string
    {
        return ScreenBuffer::fromFrame(self::rendered($pane))->plainText();
    }

    private static function rendered(ScrollPane $pane): Frame
    {
        $frame = new Frame(40, 18);
        (new RenderContext(Theme::dark()))->renderRoot($pane, $frame, $frame->area());

        return $frame;
    }

    private static function area(): Rect
    {
        return Rect::fromSize(40, 18);
    }
}
