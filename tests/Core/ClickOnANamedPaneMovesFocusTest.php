<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Core;

use Elmphant\Tui\Core\App;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Component;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Layout\Layout;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseButton;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Widget\TextInput;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ClickOnANamedPaneMovesFocusTest extends TestCase
{
    /**
     * Which panes the keyboard may reach is the tree's answer, not a question about
     * what each widget implements. A pane its host draws is one of them.
     */
    #[Test]
    public function aClickOnAPlainPaneAsksForTheKeyboard(): void
    {
        $clicked = (new FieldAbovePlainPaneApp())->update(
            new MouseMsg(0, 1, MouseAction::Press, MouseButton::Left),
            new Rect(0, 0, 20, 2),
        );

        self::assertEquals(Cmd::focus(ComponentId::named('pane')), $clicked->command);
    }
}

final readonly class FieldAbovePlainPaneApp extends App
{
    public function __construct(public TextInput $field = new TextInput())
    {
    }

    public function view(): Widget
    {
        return Layout::vertical(
            Component::bind('field', $this->field, static fn (self $app, TextInput $w): self => $app->cloneWith(field: $w)),
            Component::named('pane', new PlainPane()),
        );
    }
}
