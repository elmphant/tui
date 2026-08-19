<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Core;

use Elmphant\Tui\Core\App;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Component;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Layout;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseButton;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Spacer;
use Elmphant\Tui\Widget\TextInput;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A press outside every component puts the keyboard down, because walking away is
 * usually what it means. An app that answers the same press by saying where the
 * keyboard goes has said something more specific, and that has to stand.
 */
final class AppKeepsTheFocusItAskedForOnAClickTest extends TestCase
{
    #[Test]
    public function anAppThatMovesTheKeyboardOnAPressIsNotOverruledByTheDefault(): void
    {
        $driven = ModelHarness::for(new ClickableChromeApp(), 40, 3)->initialize();

        $driven->dispatch(new MouseMsg(0, 0, MouseAction::Press, MouseButton::Left));

        self::assertTrue(
            $driven->focused()?->equals(ComponentId::named('second')),
            'the app answered the press and named where the keys go, so the keys have to be there',
        );
    }
}

final readonly class ClickableChromeApp extends App
{
    public function __construct(
        public TextInput $first = new TextInput(),
        public TextInput $second = new TextInput(),
    ) {
    }

    public function view(): Widget
    {
        return Layout::vertical(
            Spacer::new(),
            Component::bind('first', $this->first, static fn (self $app, TextInput $field): self => $app->cloneWith(first: $field)),
            Component::bind('second', $this->second, static fn (self $app, TextInput $field): self => $app->cloneWith(second: $field)),
        );
    }

    protected function onMouse(MouseMsg $message, Rect $screen): UpdateResult
    {
        return $message->y === 0
            ? $this->withCmd(Cmd::focus(ComponentId::named('second')))
            : UpdateResult::keep($this);
    }
}
