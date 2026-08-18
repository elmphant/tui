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
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Widget\TextInput;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NamedPaneIsNotTabbedIntoTest extends TestCase
{
    /**
     * Tab offers the focused component the press first, in case it walks parts of
     * its own. A pane its host draws has nothing to offer it to, so the keyboard
     * moves on instead.
     */
    #[Test]
    public function tabOnAPlainPaneMovesTheKeyboardOn(): void
    {
        $result = (new PlainPaneBesideAFieldApp())->update(
            new KeyMsg(Key::Tab),
            new Rect(0, 0, 20, 2),
            new FocusState(ComponentId::named('pane')),
        );

        self::assertEquals(Cmd::focusNext(), $result->command);
    }
}

final readonly class PlainPaneBesideAFieldApp extends App
{
    public function __construct(public TextInput $field = new TextInput())
    {
    }

    public function view(): Widget
    {
        return Layout::vertical(
            Component::named('pane', new PlainPane()),
            Component::bind('field', $this->field, static fn (self $app, TextInput $w): self => $app->cloneWith(field: $w)),
        );
    }
}
