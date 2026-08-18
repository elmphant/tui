<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Core;

use Elmphant\Tui\Core\App;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Component;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\FocusState;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Widget\Panel;
use Elmphant\Tui\Widget\TextInput;
use Elmphant\Tui\Widget\Widget;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NamedFocusablePaneIsNotWrittenBackTest extends TestCase
{
    /**
     * A named pane may still be a Focusable — a Panel is one. Tab offers it the
     * press, and if it answers there is nowhere to put the answer.
     */
    #[Test]
    public function tabOnANamedPanelMovesTheKeyboardOnInsteadOfStoringItsAnswer(): void
    {
        try {
            $result = (new NamedPanelApp())->update(
                new KeyMsg(Key::Tab),
                new Rect(0, 0, 20, 6),
                new FocusState(ComponentId::named('pane')),
            );
        } catch (LogicException $thrown) {
            self::fail('The press was offered to a pane nothing can write back: ' . $thrown->getMessage());
        }

        self::assertEquals(Cmd::focusNext(), $result->command);
    }
}

final readonly class NamedPanelApp extends App
{
    public function view(): Widget
    {
        return Component::named(
            'pane',
            Panel::new('Pane')->withChildren([
                TextInput::new()->withFocus(true),
                TextInput::new(),
            ]),
        );
    }
}
