<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Core\App;
use Elmphant\Tui\Core\Component;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\FocusState;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Widget\TabBar;
use Elmphant\Tui\Widget\TextInput;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Which child of a pane the keyboard is on is the pane's own cursor. Routing marks
 * the component holding the keyboard on every single message, and taking focus is
 * how a bar is told to seat that cursor at the start — so marking a bar that
 * already holds it walks the reader back to the first field between one keystroke
 * and the next, and their typing lands there.
 */
final class RefocusingATabBarLeavesItsCursorAloneTest extends TestCase
{
    #[Test]
    public function aCharacterLandsInTheFieldTheReaderTabbedTo(): void
    {
        $area = new Rect(0, 0, 60, 20);
        $onTheBar = new FocusState(ComponentId::named('bar'));

        $tabbed = (new TwoFieldsInATabApp())->update(new KeyMsg(Key::Tab), $area, $onTheBar)->model;
        $typed = $tabbed->update(new KeyMsg('x'), $area, $onTheBar)->model;

        self::assertSame(
            ['', 'x'],
            array_map(
                static fn (Widget $field): string => $field instanceof TextInput ? $field->value() : '?',
                $typed->bar()->children(),
            ),
            'the character belongs to the field the reader walked to, not the one they left',
        );
    }
}

final readonly class TwoFieldsInATabApp extends App
{
    public function __construct(private ?TabBar $bar = null)
    {
    }

    public function bar(): TabBar
    {
        return $this->bar ?? TabBar::new()->addTab('Form')->add(new TextInput())->add(new TextInput());
    }

    public function view(): Widget
    {
        return Component::bind(
            'bar',
            $this->bar(),
            static fn (self $app, TabBar $bar): self => $app->cloneWith(bar: $bar),
        );
    }
}
