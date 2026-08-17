<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Core;

use Elmphant\Tui\Core\App;
use Elmphant\Tui\Core\Component;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\FocusCmd;
use Elmphant\Tui\Core\FocusState;
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
        $app = new RebindableFocusApp();

        $command = $app->update(new KeyMsg('n', ctrl: true), new Rect(0, 0, 40, 6), ComponentId::named('first'))->command;

        self::assertInstanceOf(FocusCmd::class, $command);
        self::assertEquals(
            ComponentId::named('second'),
            $command->applyTo(new FocusState(ComponentId::named('first')), $app->focusableIds())->focused(),
        );
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
