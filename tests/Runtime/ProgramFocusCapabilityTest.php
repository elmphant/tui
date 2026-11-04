<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\FocusState;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Rendering\Context\RenderPass;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Runtime\RoutesFocus;
use Elmphant\Tui\Runtime\Trace\TraceEvent;
use Elmphant\Tui\Testing\InMemoryTracer;
use Elmphant\Tui\Testing\VirtualTerminal;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProgramFocusCapabilityTest extends TestCase
{
    #[Test]
    public function anyModelThatRoutesFocusIsToldWhereTheKeyboardIs(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $terminal->simulateInput('q');

        $final = Program::new(new FocusRoutingModel())->withTerminal($terminal)->start();

        self::assertInstanceOf(FocusRoutingModel::class, $final);
        self::assertEquals(ComponentId::named('pane'), $final->seen);
    }

    /**
     * Clearing focus must remain distinct from undecided focus, or the runtime would focus the first field again.
     */
    #[Test]
    public function theKeyboardStaysNowhereWhenAnAppAsksForNoFocus(): void
    {
        $tracer = new InMemoryTracer();
        $terminal = new VirtualTerminal(40, 6);
        $terminal->simulateInput('x');

        Program::new(new KeyboardStartsWithTheAppApp(TextInput::new()))
            ->withTerminal($terminal)
            ->withTracer($tracer)
            ->start();

        $landedOn = array_values(array_filter(array_map(
            static fn (array $update): mixed => $update['focused'] ?? null,
            $tracer->fieldsOf(TraceEvent::Update),
        )));

        self::assertSame([], $landedOn, 'nowhere is a decision, not the absence of one');
    }

    #[Test]
    public function aFocusCommandForAComponentNotOnScreenLeavesTheKeyboardWhereItIs(): void
    {
        $tracer = new InMemoryTracer();
        $terminal = new VirtualTerminal(40, 6);
        $terminal->simulateInput("\t\x07x\x11");

        $final = Program::new(new FocusElsewhereApp(TextInput::new(), TextInput::new()))
            ->withTerminal($terminal)
            ->withTracer($tracer)
            ->start();

        self::assertInstanceOf(FocusElsewhereApp::class, $final);
        self::assertSame('x', $final->second->value());
        self::assertSame(
            ['from' => 'second', 'to' => 'second', 'requested' => 'hidden/composer'],
            array_slice($tracer->fieldsOf(TraceEvent::Focus), -1)[0] ?? null,
        );
    }
}

final readonly class FocusElsewhereApp extends App
{
    public function __construct(
        public TextInput $first,
        public TextInput $second,
    ) {
    }

    public function view(): Widget
    {
        return Layout::vertical(
            Component::bind('first', $this->first, static fn (self $app, TextInput $field): self => $app->cloneWith(first: $field)),
            Component::bind('second', $this->second, static fn (self $app, TextInput $field): self => $app->cloneWith(second: $field)),
        );
    }

    protected function onKey(KeyMsg $message, Rect $screen): UpdateResult
    {
        return $message->ctrl && $message->key === 'g'
            ? new UpdateResult($this, Cmd::focus(ComponentId::named('hidden/composer')))
            : UpdateResult::ignored($this);
    }
}

final readonly class KeyboardStartsWithTheAppApp extends App
{
    public function __construct(
        public TextInput $first,
    ) {
    }

    public function view(): Widget
    {
        return Layout::vertical(
            Component::bind(
                'first',
                $this->first,
                static fn (self $app, TextInput $field): self => $app->cloneWith(first: $field),
            ),
        );
    }

    protected function setup(): ?Cmd
    {
        return Cmd::focusNowhere();
    }

    protected function onKey(KeyMsg $message, Rect $screen): UpdateResult
    {
        return new UpdateResult($this, Cmd::quit());
    }
}

final readonly class FocusRoutingModel implements RoutesFocus
{
    public function __construct(public ?ComponentId $seen = null)
    {
    }

    public function init(): ?Cmd
    {
        return Cmd::focus(ComponentId::named('pane'));
    }

    public function update(Message $message, Rect $area, ?FocusState $focus = null): UpdateResult
    {
        return $message instanceof KeyMsg && 'q' === $message->key
            ? new UpdateResult(new self($focus?->focused()), Cmd::quit())
            : new UpdateResult($this);
    }

    /**
     * @return list<ComponentId>
     */
    public function ringIds(?FocusState $focus = null): array
    {
        return [ComponentId::named('pane')];
    }

    /**
     * @return list<ComponentId>
     */
    public function reachableIds(): array
    {
        return [ComponentId::named('pane')];
    }

    public function enclosingIds(ComponentId $id): array
    {
        return [];
    }

    public function render(Frame $frame, RenderPass $pass): void
    {
    }
}
