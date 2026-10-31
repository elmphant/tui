<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\ErrorMsg;
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

final class ProgramErrorHandlerTest extends TestCase
{
    #[Test]
    public function anErrorHandlerThatKeepsFeedingAFailingUpdateIsGivenUpOn(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('retry failed');

        Program::new(new FailsOnRetryModel())
            ->withTerminal(new VirtualTerminal(20, 3))
            ->onError(static fn (ErrorMsg $error): Cmd => Cmd::message(new Retry()))
            ->start();
    }

    #[Test]
    public function anErrorHandlerThatThrowsIsTracedAndReportedOnceTheTerminalIsRestored(): void
    {
        $tracer = new InMemoryTracer();
        $terminal = new VirtualTerminal(20, 3);
        EventLoop::delay(0.1, static fn () => $terminal->closeInput());

        Program::new(new FailsOnceThenQuitsModel())
            ->withTerminal($terminal)
            ->withTracer($tracer)
            ->onError(static fn (ErrorMsg $error): Cmd => throw new RuntimeException('error handler itself broke'))
            ->start();

        self::assertSame(
            ["once\e]0;title\x07", 'error handler itself broke'],
            array_column($tracer->fieldsOf(TraceEvent::Error), 'message'),
        );
        self::assertStringContainsString('1 error went unhandled while the app ran. The last one: RuntimeException: error handler itself broke', $terminal->output());
    }

    #[Test]
    public function aTracedErrorKeepsTheTopTwelveLinesOfItsStack(): void
    {
        $tracer = new InMemoryTracer();
        $terminal = new VirtualTerminal(20, 3);
        EventLoop::delay(0.1, static fn () => $terminal->closeInput());

        Program::new(new FailsOnceThenQuitsModel(depth: 20))->withTerminal($terminal)->withTracer($tracer)->start();

        $stack = $tracer->fieldsOf(TraceEvent::Error)[0]['trace'] ?? [];
        self::assertIsArray($stack);
        self::assertCount(12, $stack);
        self::assertStringStartsWith('#0 ', (string)$stack[0]);
    }

    #[Test]
    public function anErrorNoHandlerHeardIsReportedOnceTheTerminalIsRestored(): void
    {
        $terminal = new VirtualTerminal(20, 3);

        Program::new(new FailsOnceThenQuitsModel())->withTerminal($terminal)->start();

        self::assertMatchesRegularExpression(
            '/1 error went unhandled while the app ran\. The last one: RuntimeException: once in ' . preg_quote(__FILE__, '/') . ':\d+\r\n$/',
            $terminal->output(),
        );
    }
}

final readonly class Retry implements Message
{
}

final readonly class FailsOnceThenQuitsModel implements Model
{
    public function __construct(private int $depth = 0)
    {
    }

    public function init(): ?Cmd
    {
        return Cmd::message(new Retry());
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        if ($message instanceof Retry) {
            self::fail($this->depth);
        }

        return new UpdateResult($this, $message instanceof ErrorMsg ? Cmd::quit() : null);
    }

    public function render(Frame $frame, RenderPass $pass): void
    {
    }

    private static function fail(int $depth): never
    {
        if ($depth > 0) {
            self::fail($depth - 1);
        }

        throw new RuntimeException("once\e]0;title\x07");
    }
}

final readonly class FailsOnRetryModel implements Model
{
    public function init(): ?Cmd
    {
        return Cmd::message(new Retry());
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        if ($message instanceof Retry) {
            throw new RuntimeException('retry failed');
        }

        return new UpdateResult($this);
    }

    public function render(Frame $frame, RenderPass $pass): void
    {
    }
}
