<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Core;

use Elmphant\Tui\Core\App;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Component;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\FocusCmd;
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

final class ClickMovesRoutedFocusTest extends TestCase
{
    /**
     * A click and a key have to agree on who holds the keyboard. Moving focus by
     * flag alone leaves the runtime pointing at whoever it pointed at before.
     */
    #[Test]
    public function aClickOnAnotherComponentAsksForTheKeyboardToMoveThere(): void
    {
        $clicked = (new TwoFieldClickApp())->update(
            new MouseMsg(1, 0, MouseAction::Press, MouseButton::Left),
            new Rect(0, 0, 40, 2),
            new FocusState(ComponentId::named('second')),
        );

        self::assertInstanceOf(FocusCmd::class, $clicked->command);
        self::assertEquals(Cmd::focus(ComponentId::named('first')), $clicked->command);
    }
}

final readonly class TwoFieldClickApp extends App
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
