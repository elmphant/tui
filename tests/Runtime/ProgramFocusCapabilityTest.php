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
     * `Cmd::focusNothing()` is how an app keeps the keyboard for itself. Undecided and
     * decided-nowhere both name no component, so a runtime comparing only the
     * component read one as the other and settled the keyboard on the first field.
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
        return Cmd::focusNothing();
    }

    protected function onKey(KeyMsg $message, Rect $screen): UpdateResult
    {
        return UpdateResult::changed($this, Cmd::quit());
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

    public function update(Message $message, Rect $area, FocusState $focus = new FocusState()): UpdateResult
    {
        return $message instanceof KeyMsg && 'q' === $message->key
            ? new UpdateResult(new self($focus->focused()), Cmd::quit())
            : new UpdateResult($this);
    }

    /**
     * @return list<ComponentId>
     */
    public function focusableIds(FocusState $focus = new FocusState()): array
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

    public function render(Frame $frame, RenderPass $pass): void
    {
    }
}
