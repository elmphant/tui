<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime\Trace;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Runtime\Trace\TraceEvent;
use Elmphant\Tui\Testing\InMemoryTracer;
use Elmphant\Tui\Testing\VirtualTerminal;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Record focus moves separately because the runtime applies them outside model updates.
 */
final class ProgramTracesTheKeyboardMovingTest extends TestCase
{
    #[Test]
    public function aMoveSaysWhereTheKeyboardWentAndWhereItCameFrom(): void
    {
        $tracer = new InMemoryTracer();
        $terminal = new VirtualTerminal(40, 6);
        $terminal->simulateInput("\t");

        Program::new(new TwoNamedFieldsApp(TextInput::new(), TextInput::new()))
            ->withTerminal($terminal)
            ->withTracer($tracer)
            ->start();

        $moves = $tracer->fieldsOf(TraceEvent::Focus);

        self::assertNotSame([], $moves, 'the tab moved the keyboard, so the trace must say so');
        self::assertSame(
            ['from' => 'first', 'to' => 'second'],
            ['from' => $moves[0]['from'] ?? null, 'to' => $moves[0]['to'] ?? null],
        );
    }
}

final readonly class TwoNamedFieldsApp extends App
{
    public function __construct(
        public TextInput $first,
        public TextInput $second,
    ) {
    }

    public function view(): Widget
    {
        return Layout::column(
            Component::bind('first', $this->first, static fn (self $app, TextInput $field): self => $app->cloneWith(first: $field)),
            Component::bind('second', $this->second, static fn (self $app, TextInput $field): self => $app->cloneWith(second: $field)),
        );
    }

    protected function setup(): Cmd
    {
        return Cmd::after(0.05, static fn (): Cmd => Cmd::quit());
    }
}
