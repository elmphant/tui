<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime\Trace;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\KeyMsg;
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
 * Where the keyboard was and what it could have moved to are the two things a
 * key that appears to do nothing turns on, so a trace that cannot say them
 * cannot explain the commonest kind of report.
 */
final class ProgramFocusTraceTest extends TestCase
{
    #[Test]
    public function anUpdateNamesTheComponentHoldingTheKeyboard(): void
    {
        $tracer = new InMemoryTracer();
        $terminal = new VirtualTerminal(40, 6);
        $terminal->simulateInput("\tq");

        Program::new(new TwoFieldsThenQuitApp(TextInput::new(), TextInput::new()))
            ->withTerminal($terminal)
            ->withTracer($tracer)
            ->start();

        $keys = array_values(array_filter(
            $tracer->fieldsOf(TraceEvent::Update),
            static fn (array $fields): bool => ($fields['message'] ?? null) === KeyMsg::class,
        ));

        self::assertNotSame([], $keys, 'keys arrived, so the trace must show them');
        self::assertSame('first', $keys[0]['focused'] ?? null, 'the keyboard was on the first field');
    }

    #[Test]
    public function anUpdateNamesEverywhereTheKeyboardCouldHaveGone(): void
    {
        $tracer = new InMemoryTracer();
        $terminal = new VirtualTerminal(40, 6);
        $terminal->simulateInput("\tq");

        Program::new(new TwoFieldsThenQuitApp(TextInput::new(), TextInput::new()))
            ->withTerminal($terminal)
            ->withTracer($tracer)
            ->start();

        $keys = array_values(array_filter(
            $tracer->fieldsOf(TraceEvent::Update),
            static fn (array $fields): bool => ($fields['message'] ?? null) === KeyMsg::class,
        ));

        self::assertNotSame([], $keys);
        self::assertSame(
            ['first', 'second'],
            $keys[0]['ring'] ?? null,
            'a tab that goes nowhere is a ring with nowhere to go, which the trace has to show',
        );
    }

    #[Test]
    public function anUpdateNamesTheKeyThatArrived(): void
    {
        $tracer = new InMemoryTracer();
        $terminal = new VirtualTerminal(40, 6);
        $terminal->simulateInput("\tq");

        Program::new(new TwoFieldsThenQuitApp(TextInput::new(), TextInput::new()))
            ->withTerminal($terminal)
            ->withTracer($tracer)
            ->start();

        $keys = array_column($tracer->fieldsOf(TraceEvent::Update), 'key');

        self::assertContains(
            'tab',
            $keys,
            'a key reported only as a class cannot answer whether the one pressed arrived at all',
        );
    }

    #[Test]
    public function movingTheKeyboardDrawsTheScreenAgain(): void
    {
        $tracer = new InMemoryTracer();
        $terminal = new VirtualTerminal(40, 6);
        // Only the tab: quitting in the same read would end the loop before it drew.
        $terminal->simulateInput("\t");

        Program::new(new TwoFieldsLeftRunningApp(TextInput::new(), TextInput::new()))
            ->withTerminal($terminal)
            ->withTracer($tracer)
            ->withFps(1000.0)
            ->start();

        $records = $tracer->records();
        $afterTheTab = null;
        foreach ($records as $index => [$event, $fields]) {
            if ($event === TraceEvent::Update && ($fields['key'] ?? null) === 'tab') {
                $afterTheTab = array_slice($records, $index + 1);

                break;
            }
        }

        self::assertNotNull($afterTheTab, 'the tab has to arrive before anything can be said about it');
        self::assertContains(
            TraceEvent::Render,
            array_column($afterTheTab, 0),
            'the keyboard moved, so what shows where it is has to be drawn again',
        );
    }
}

/**
 * Stops on its own after a moment, so the loop has iterations left to draw in.
 */
final readonly class TwoFieldsLeftRunningApp extends App
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

    protected function setup(): Cmd
    {
        return Cmd::after(0.05, static fn (): Cmd => Cmd::quit());
    }
}

final readonly class TwoFieldsThenQuitApp extends App
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
        return $message->key === 'q' ? $this->withCmd(Cmd::quit()) : UpdateResult::keep($this);
    }
}
