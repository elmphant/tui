<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Core\Cmd\FocusCmd;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\FocusState;
use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AppFocusCyclingTest extends TestCase
{
    #[Test]
    public function anAppMayBindFocusCyclingToAKeyOfItsOwn(): void
    {
        $app = new RebindableFocusApp(TextInput::new(), TextInput::new());

        $command = $app->update(new KeyMsg('n', ctrl: true), new Rect(0, 0, 40, 6), new FocusState(ComponentId::named('first')))->command;

        self::assertInstanceOf(FocusCmd::class, $command);
        self::assertEquals(
            ComponentId::named('second'),
            $command->applyTo(new FocusState(ComponentId::named('first')), $app->focusableIds(), $app->reachableIds())?->focused(),
        );
    }

    #[Test]
    public function shiftTabWalksTheOtherWayRound(): void
    {
        $app = new PlainlyCyclingApp(TextInput::new(), TextInput::new());
        $onTheSecond = new FocusState(ComponentId::named('second'));

        $command = $app->update(
            new KeyMsg(Key::Tab, shift: true),
            new Rect(0, 0, 40, 6),
            $onTheSecond,
        )->command;

        self::assertInstanceOf(FocusCmd::class, $command);
        self::assertEquals(
            ComponentId::named('first'),
            $command->applyTo($onTheSecond, $app->focusableIds(), $app->reachableIds())?->focused(),
            'shift+tab is the walk backwards, or the keyboard could only ever go one way',
        );
    }
}

final readonly class PlainlyCyclingApp extends App
{
    public function __construct(
        public TextInput $first,
        public TextInput $second,
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
}

final readonly class RebindableFocusApp extends App
{
    public function __construct(
        public TextInput $first,
        public TextInput $second,
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
