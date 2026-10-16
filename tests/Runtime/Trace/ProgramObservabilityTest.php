<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime\Trace;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\ErrorMsg;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Rendering\Context\RenderPass;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Runtime\Model;
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Runtime\Trace\TraceEvent;
use Elmphant\Tui\Testing\InMemoryTracer;
use Elmphant\Tui\Testing\VirtualTerminal;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use RuntimeException;

final class ProgramObservabilityTest extends TestCase
{
    #[Test]
    public function anUpdateThatThrowsIsTracedWithWhatWentWrongAndWhere(): void
    {
        $tracer = new InMemoryTracer();

        self::pressing(new ScriptedModel(throwOn: 'x'), $tracer, 'x');

        $errors = $tracer->fieldsOf(TraceEvent::Error);
        self::assertCount(1, $errors);
        self::assertSame('update', $errors[0]['source']);
        self::assertSame(RuntimeException::class, $errors[0]['class']);
        self::assertSame('x went wrong', $errors[0]['message']);
        self::assertSame(__FILE__, $errors[0]['file']);
        self::assertIsInt($errors[0]['line']);
        self::assertIsArray($errors[0]['trace']);
        self::assertNotSame([], $errors[0]['trace']);
    }

    #[Test]
    public function theErrorTheProgramGivesUpOnIsTracedAsFatal(): void
    {
        $tracer = new InMemoryTracer();

        try {
            self::pressing(new ScriptedModel(throwOn: 'x', alsoOnErrors: true), $tracer, 'x');
            self::fail('ten failed updates in a row end the program');
        } catch (RuntimeException) {
        }

        $sources = array_column($tracer->fieldsOf(TraceEvent::Error), 'source');
        self::assertSame('fatal', end($sources), implode(',', $sources));
    }

    #[Test]
    public function tracingIsSwitchedOnByAnEnvironmentVariable(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'trace');
        putenv('ELMPHANT_TRACE=' . $path);

        try {
            $terminal = new VirtualTerminal(30, 4);
            EventLoop::delay(0.02, static fn () => $terminal->simulateInput('q'));
            Program::new(new ScriptedModel())->withTerminal($terminal)->start();
        } finally {
            putenv('ELMPHANT_TRACE');
        }

        $events = array_map(
            static fn (string $line): mixed => json_decode($line, true, flags: JSON_THROW_ON_ERROR)['event'],
            file($path, FILE_IGNORE_NEW_LINES) ?: [],
        );
        unlink($path);

        self::assertContains('update', $events);
    }

    #[Test]
    public function anAppLogsThroughTheTraceWithoutTouchingTheScreen(): void
    {
        $tracer = new InMemoryTracer();
        $terminal = new VirtualTerminal(30, 4);
        EventLoop::delay(0.02, static fn () => $terminal->simulateInput('q'));

        Program::new(new ScriptedModel(init: Cmd::log('loaded the list', ['items' => 3])))
            ->withTerminal($terminal)
            ->withTracer($tracer)
            ->start();

        self::assertSame([['items' => 3, 'message' => 'loaded the list']], $tracer->fieldsOf(TraceEvent::Log));
        self::assertStringNotContainsString('loaded the list', $terminal->output());
    }

    #[Test]
    public function whatAModelEchoesGoesToTheTraceInsteadOfTheScreen(): void
    {
        $tracer = new InMemoryTracer();
        $terminal = new VirtualTerminal(30, 4);
        EventLoop::delay(0.02, static fn () => $terminal->simulateInput('q'));

        Program::new(new EchoingModel())->withTerminal($terminal)->withTracer($tracer)->start();

        $outputs = $tracer->fieldsOf(TraceEvent::Output);
        self::assertContains(['during' => 'init', 'text' => 'starting'], $outputs);
        self::assertContains(['during' => 'update', 'text' => 'saw a key'], $outputs);
        self::assertContains(['during' => 'render', 'text' => 'drawing'], $outputs);
        self::assertStringNotContainsString('saw a key', $terminal->output());
    }

    private static function pressing(Model $model, InMemoryTracer $tracer, string $keys): void
    {
        $terminal = new VirtualTerminal(30, 4);
        EventLoop::delay(0.02, static fn () => $terminal->simulateInput($keys . 'q'));

        Program::new($model)->withTerminal($terminal)->withTracer($tracer)->start();
    }
}

final class EchoingModel implements Model
{
    public function init(): ?Cmd
    {
        echo 'starting';

        return null;
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        if (!$message instanceof KeyMsg) {
            return new UpdateResult($this);
        }

        echo 'saw a key';

        return new UpdateResult($this, Cmd::quit());
    }

    public function render(Frame $frame, RenderPass $pass): void
    {
        echo 'drawing';
    }
}

final class ScriptedModel implements Model
{
    public function __construct(
        private readonly ?string $throwOn = null,
        private readonly bool $alsoOnErrors = false,
        private readonly ?Cmd $init = null,
    ) {
    }

    public function init(): ?Cmd
    {
        return $this->init;
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        if ($message instanceof KeyMsg && $message->key === $this->throwOn) {
            throw new RuntimeException($message->key . ' went wrong');
        }
        if ($message instanceof ErrorMsg && $this->alsoOnErrors) {
            throw new RuntimeException('handling it went wrong too');
        }

        return new UpdateResult($this, $message instanceof KeyMsg && $message->key === 'q' ? Cmd::quit() : null);
    }

    public function render(Frame $frame, RenderPass $pass): void
    {
    }
}
