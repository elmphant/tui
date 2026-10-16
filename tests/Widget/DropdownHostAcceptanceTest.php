<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\FocusState;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseButton;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Rendering\Context\RenderPass;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Runtime\InputRouter;
use Elmphant\Tui\Testing\ScreenBuffer;
use Elmphant\Tui\Tests\Support\RootPass;
use Elmphant\Tui\Widget\Choice\Select;
use Elmphant\Tui\Widget\Popup\Dropdown;
use Elmphant\Tui\Widget\Popup\DropdownPopup;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Structure\ScrollPane;
use Elmphant\Tui\Widget\Widget;
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

        [$firstFrame, $pass] = self::drawn($pane);

        $placements = $pass->floats->placements();
        self::assertCount(1, $placements, 'the popup is on the first frame, drawn through the fallback layout path');

        $popup = $placements[0]->float;
        self::assertInstanceOf(DropdownPopup::class, $popup);

        $routed = InputRouter::route(
            new MouseMsg(2, $popup->contentArea($placements[0]->placement)->y, MouseAction::Press, MouseButton::Left),
            $firstFrame->area(),
            $placements,
        );
        $picked = self::handed($pane, $routed);

        self::assertSame('json', self::dropdownAt($picked, 0)->content()->selectedValue());
    }

    #[Test]
    public function theKeyboardStillWorksAfterAPickedOption(): void
    {
        $pane = self::paneWithTwoDropdowns(openFirst: true);
        $row = self::rowOf($pane, 'yaml');

        $picked = self::deliver($pane, new MouseMsg(2, $row, MouseAction::Press, MouseButton::Left));

        self::assertFalse(self::dropdownAt($picked, 0)->isOpen(), 'the pick closed the popup');

        $reopened = self::handed($picked, new KeyMsg(' ', screen: self::area()));

        self::assertTrue(self::dropdownAt($reopened, 0)->isOpen(), 'space still reaches the focused dropdown');
    }

    private static function deliver(ScrollPane $pane, MouseMsg $mouse): ScrollPane
    {
        return self::handed($pane, self::route($pane, $mouse));
    }

    /**
     * The pane publishes its dropdowns, so a host is what routes to one — the pane
     * itself only scrolls.
     */
    private static function handed(ScrollPane $pane, Message $message): ScrollPane
    {
        $updated = (new DropdownPaneApp($pane))->update(
            $message,
            self::area(),
            new FocusState(ComponentId::named('pane/0')),
        )->model;

        if (!$updated instanceof DropdownPaneApp) {
            throw new RuntimeException('the host must stay a host');
        }

        return $updated->pane;
    }

    private static function route(ScrollPane $pane, MouseMsg $mouse): Message
    {
        [$frame, $pass] = self::drawn($pane);

        return InputRouter::route($mouse, $frame->area(), $pass->floats->placements());
    }

    /**
     * As the runtime draws a bound pane; a bare RenderContext::focused() names no child.
     *
     * @return array{Frame, RenderPass}
     */
    private static function drawn(ScrollPane $pane): array
    {
        $frame = new Frame(40, 18);
        $pass = RenderPass::new(Theme::dark());
        $pass->begin(0.0);
        $pass->focus->focusOn(new FocusState(ComponentId::named('pane/0')));
        RootPass::draw($pass, (new DropdownPaneApp($pane))->view(), $frame);
        $pass->floats->commit();

        return [$frame, $pass];
    }

    private static function paneWithTwoDropdowns(bool $openFirst): ScrollPane
    {
        $first = Dropdown::new(Select::new(['json', 'yaml']));
        $pane = ScrollPane::of($first, Dropdown::new(Select::new(['toml', 'ini'])), Text::new('beneath'));

        return $openFirst
            ? self::openedPane($pane)
            : $pane;
    }

    private static function paneWithManyRows(bool $openFirst): ScrollPane
    {
        $options = array_map(static fn (int $index): string => 'option' . $index, range(1, 30));
        $pane = ScrollPane::of(
            Dropdown::new(Select::new($options)),
            ...array_map(static fn (int $row): Text => Text::new('row' . $row), range(1, 20)),
        );

        return $openFirst
            ? self::openedPane($pane)
            : $pane;
    }

    private static function openedPane(ScrollPane $pane): ScrollPane
    {
        return self::handed($pane, new KeyMsg(' ', screen: self::area()));
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
        return self::drawn($pane)[0];
    }

    private static function area(): Rect
    {
        return Rect::fromSize(40, 18);
    }
}

final readonly class DropdownPaneApp extends App
{
    public function __construct(public ScrollPane $pane)
    {
    }

    public function view(): Widget
    {
        return Component::bind('pane', $this->pane, static fn (self $app, ScrollPane $pane): self => $app->cloneWith(pane: $pane));
    }
}
