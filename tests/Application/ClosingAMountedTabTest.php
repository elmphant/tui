<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Application\Mount;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\Reducer;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Support\Cloneable;
use Elmphant\Tui\Testing\TestClock;
use Elmphant\Tui\Testing\VirtualTerminal;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Structure\ScrollPane;
use Elmphant\Tui\Widget\Structure\TabBar;
use Elmphant\Tui\Widget\Structure\TabPage;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;

final class ClosingAMountedTabTest extends TestCase
{
    #[Test]
    public function closingTheTabThatHoldsTheKeyboardLandsItInTheNextTab(): void
    {
        $terminal = new VirtualTerminal(40, 8);
        $clock = new TestClock(1000.0);
        $program = Program::new(RoomsApp::of('general', 'random'))->withTerminal($terminal)->withClock($clock);

        EventLoop::defer(static function () use ($terminal, $clock, $program): void {
            foreach (["\x1bOQ", 'x', "\x11"] as $input) {
                $clock->advance(1);
                $program->tick();
                $terminal->simulateInput($input);
            }
        });
        $app = $program->start();

        self::assertInstanceOf(RoomsApp::class, $app);
        self::assertSame('', $app->switcher->value());
        self::assertSame('x', $app->rooms['random']->composer->value());
    }
}

final readonly class ChatRoom implements Reducer
{
    use Cloneable;

    public function __construct(public TextInput $composer)
    {
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return new UpdateResult($this);
    }

    public function view(): Widget
    {
        return Layout::column(Component::bind('composer', $this->composer, static fn (self $room, TextInput $composer): self => $room->cloneWith(composer: $composer)));
    }
}

final readonly class RoomsApp extends App
{
    use Cloneable;

    /**
     * @param array<string, ChatRoom> $rooms
     */
    public function __construct(public array $rooms, public TextInput $switcher)
    {
    }

    public static function of(string ...$names): self
    {
        return new self(array_fill_keys($names, new ChatRoom(TextInput::new())), TextInput::new());
    }

    public function view(): Widget
    {
        $pages = [];
        foreach ($this->rooms as $name => $room) {
            $pages[] = new TabPage($name, ScrollPane::of(Mount::named($name, $room, $room->view(), static fn (self $app, ChatRoom $room): self => $app->cloneWith(rooms: [...$app->rooms, $name => $room]))));
        }

        return Layout::column()
            ->add(Component::bind('switcher', $this->switcher, static fn (self $app, TextInput $switcher): self => $app->cloneWith(switcher: $switcher)), Constraint::exact(1))
            ->add(Component::bind('tabs', TabBar::new()->withTabs($pages), static fn (self $app): self => $app), Constraint::flex());
    }

    protected function setup(): Cmd
    {
        return Cmd::focus(ComponentId::named(array_key_first($this->rooms) . '/composer'));
    }

    protected function onKey(KeyMsg $message, Rect $screen): UpdateResult
    {
        if ($message->key !== Key::F2) {
            return new UpdateResult($this);
        }
        $rooms = $this->rooms;
        unset($rooms[array_key_first($rooms)]);

        return new UpdateResult($this->cloneWith(rooms: $rooms));
    }
}
