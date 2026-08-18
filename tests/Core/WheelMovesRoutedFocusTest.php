<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Core;

use Elmphant\Tui\Core\App;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Component;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\FocusState;
use Elmphant\Tui\Layout\Layout;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseButton;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Widget\TextInput;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Turning a wheel over a pane is working in it, so the keyboard belongs there
 * afterwards — a reader who scrolls a list and then presses a key means both for
 * the same pane.
 */
final class WheelMovesRoutedFocusTest extends TestCase
{
    #[Test]
    public function aWheelOverAnotherComponentAsksForTheKeyboardToMoveThere(): void
    {
        $turned = (new TwoFieldWheelApp())->update(
            new MouseMsg(1, 0, MouseAction::WheelDown, MouseButton::None),
            new Rect(0, 0, 40, 2),
            new FocusState(ComponentId::named('second')),
        );

        self::assertEquals(Cmd::focus(ComponentId::named('first')), $turned->command);
    }

    #[Test]
    public function aWheelOverNothingLeavesTheKeyboardWhereItWas(): void
    {
        $turned = (new TwoFieldWheelApp())->update(
            new MouseMsg(1, 30, MouseAction::WheelDown, MouseButton::None),
            new Rect(0, 0, 40, 2),
            new FocusState(ComponentId::named('second')),
        );

        self::assertNull($turned->command, 'a click on empty space means "not here"; a wheel there means nothing');
    }
}

final readonly class TwoFieldWheelApp extends App
{
    public function __construct(
        public TextInput $first = new TextInput(),
        public TextInput $second = new TextInput(),
    ) {
    }

    public function view(): Widget
    {
        return Layout::vertical(
            Component::bind('first', $this->first, static fn (self $app, TextInput $w): self => $app->cloneWith(first: $w)),
            Component::bind('second', $this->second, static fn (self $app, TextInput $w): self => $app->cloneWith(second: $w)),
        );
    }
}
