<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Core;

use Elmphant\Tui\Core\App;
use Elmphant\Tui\Core\Component;
use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Layout\Layout;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Widget\TextInput;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AppFocusCyclingTest extends TestCase
{
    #[Test]
    public function anAppMayBindFocusCyclingToAKeyOfItsOwn(): void
    {
        $app = new RebindableFocusApp(TextInput::new()->withFocus(true), TextInput::new());

        $cycled = $app->update(new KeyMsg('n', ctrl: true), new Rect(0, 0, 40, 6))->model;

        self::assertInstanceOf(RebindableFocusApp::class, $cycled);
        self::assertFalse($cycled->first->isFocused(), 'The bound key must move the focus off the first field');
        self::assertTrue($cycled->second->isFocused(), 'and on to the second');
    }
}

final readonly class RebindableFocusApp extends App
{
    public function __construct(
        public TextInput $first = new TextInput(),
        public TextInput $second = new TextInput(),
    ) {
    }

    public function view(): Widget
    {
        return Layout::vertical(
            Component::bind(
                'first',
                $this->first,
                static fn (self $app, TextInput $input): self => $app->cloneWith(first: $input),
            ),
            Component::bind(
                'second',
                $this->second,
                static fn (self $app, TextInput $input): self => $app->cloneWith(second: $input),
            ),
        );
    }

    protected function focusCycleBindings(): Keybindings
    {
        return new Keybindings([Actions::FOCUS_CYCLE => ['ctrl+n']]);
    }
}
