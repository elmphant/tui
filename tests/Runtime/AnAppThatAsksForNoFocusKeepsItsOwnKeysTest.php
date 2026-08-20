<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use Elmphant\Tui\Core\App;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Component;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Layout;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Runtime\Trace\TraceEvent;
use Elmphant\Tui\Testing\InMemoryTracer;
use Elmphant\Tui\Testing\VirtualTerminal;
use Elmphant\Tui\Widget\TextInput;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `Cmd::focusNothing()` is how an app says the keyboard starts with it rather than
 * with a field. Undecided focus and decided-nowhere both name no component, so a
 * runtime comparing only the component reads the one as the other, leaves the move
 * unapplied, and lets the first field take every key the app meant to answer.
 */
final class AnAppThatAsksForNoFocusKeepsItsOwnKeysTest extends TestCase
{
    #[Test]
    public function theKeyboardStaysNowhereRatherThanLandingOnTheFirstField(): void
    {
        $tracer = new InMemoryTracer();
        $terminal = new VirtualTerminal(40, 6);
        $terminal->simulateInput('x');

        Program::new(new KeyboardStartsWithTheAppApp())
            ->withTerminal($terminal)
            ->withTracer($tracer)
            ->start();

        $landedOn = array_values(array_filter(array_map(
            static fn (array $update): mixed => $update['focused'] ?? null,
            $tracer->fieldsOf(TraceEvent::Update),
        )));

        self::assertSame(
            [],
            $landedOn,
            'nowhere is a decision, not the absence of one, so nothing may settle the keyboard onto a field',
        );
    }
}

final class KeysHeard
{
    /**
     * @var list<string>
     */
    public static array $keys = [];
}

final readonly class KeyboardStartsWithTheAppApp extends App
{
    public function __construct(
        public TextInput $first = new TextInput(),
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
        if (\is_string($message->key)) {
            KeysHeard::$keys[] = $message->key;
        }

        return UpdateResult::changed($this, Cmd::quit());
    }
}
