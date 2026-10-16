<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use Elmphant\Tui\Core\App;
use Elmphant\Tui\Core\Component;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Layout;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Testing\ScreenBuffer;
use Elmphant\Tui\Testing\TestClock;
use Elmphant\Tui\Testing\VirtualTerminal;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Popup\Modal;
use Elmphant\Tui\Widget\Popup\Overlay;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;

final class ProgramFocusBeforeDrawTest extends TestCase
{
    #[Test]
    public function theFrameDrawnRightAfterAModalOpensHasTheCaretInTheModal(): void
    {
        $terminal = new VirtualTerminal(40, 12);
        $clock = new TestClock(1000.0);
        $program = Program::new(new FieldBehindModalApp())->withTerminal($terminal)->withClock($clock);
        $caretRow = null;

        EventLoop::defer(static function () use ($terminal, $clock, $program, &$caretRow): void {
            $clock->advance(1);
            $program->tick();
            $terminal->simulateInput("\x1bOQ");
            $clock->advance(1);
            $program->tick();
            $caretRow = (new ScreenBuffer($terminal->output(), 40, 12))->cursorPosition()->row;
            $terminal->simulateInput("\x11");
        });
        $program->start();

        self::assertGreaterThan(0, $caretRow, 'the caret is still on the field behind the modal');
    }
}

final readonly class FieldBehindModalApp extends App
{
    public function __construct(public TextInput $field = new TextInput(), public ?Modal $modal = null)
    {
    }

    public function view(): Widget
    {
        $main = Layout::vertical(Component::bind('field', $this->field, static fn (self $app, TextInput $field): self => $app->cloneWith(field: $field)));

        return $this->modal === null
            ? $main
            : Overlay::new($main, Component::bind('modal', $this->modal, static fn (self $app, Modal $modal): self => $app->cloneWith(modal: $modal)));
    }

    protected function onKey(KeyMsg $message, Rect $screen): UpdateResult
    {
        return $message->key === Key::F2
            ? UpdateResult::changed($this->cloneWith(modal: Modal::new('M', TextInput::new('inside'))))
            : UpdateResult::keep($this);
    }
}
