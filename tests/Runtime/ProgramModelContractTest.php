<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\EffectId;
use Elmphant\Tui\Core\Model;
use Elmphant\Tui\Core\Reducer;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Message\TickMsg;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Runtime\RunningProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SplQueue;

final class ProgramModelContractTest extends TestCase
{
    #[Test]
    public function anInvalidModelResultIsContainedByTheErrorCircuitBreaker(): void
    {
        $updates = 0;
        $model = new class($updates) implements Model {
            public function __construct(public int &$updates)
            {
            }

            public function init(): ?Cmd
            {
                return null;
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                ++$this->updates;

                return new UpdateResult(new NonModelReducer());
            }

            public function render(Frame $frame, RenderContext $context): void
            {
            }
        };
        $program = Program::new($model);
        $reflection = new ReflectionClass($program);
        /** @var SplQueue<Message> $queue */
        $queue = new SplQueue();
        $queue->enqueue(new TickMsg(1.0));
        $reflection->getProperty('model')->setValue($program, $model);
        $reflection->getProperty('messageQueue')->setValue($program, $queue);
        $reflection->getProperty('running')->setValue($program, true);

        $reflection->getMethod('drainMessageQueue')->invoke($program);

        self::assertFalse($reflection->getProperty('running')->getValue($program));
        self::assertSame(10, $updates);
    }

    #[Test]
    public function cancellationKeepsNonmatchingProcesses(): void
    {
        $program = Program::new($this->validModel());
        $matching = new CancellationProbe(EffectId::named('matching'));
        $other = new CancellationProbe(EffectId::named('other'));
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('processes')->setValue($program, [$other, $matching]);
        $reflection->getProperty('messageQueue')->setValue($program, new SplQueue());

        $reflection->getMethod('dispatchCommand')->invoke(
            $program,
            Cmd::cancel(EffectId::named('matching')),
        );

        self::assertFalse($other->stopped);
        self::assertTrue($matching->stopped);
        self::assertSame([$other], $reflection->getProperty('processes')->getValue($program));
    }

    #[Test]
    public function cancellationPreservesProcessesAfterAMatchingProcess(): void
    {
        $program = Program::new($this->validModel());
        $matching = new CancellationProbe(EffectId::named('matching'));
        $later = new CancellationProbe(EffectId::named('later'));
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('processes')->setValue($program, [$matching, $later]);
        $reflection->getProperty('messageQueue')->setValue($program, new SplQueue());

        $reflection->getMethod('dispatchCommand')->invoke(
            $program,
            Cmd::cancel(EffectId::named('matching')),
        );

        self::assertTrue($matching->stopped);
        self::assertFalse($later->stopped);
        self::assertSame([$later], $reflection->getProperty('processes')->getValue($program));
    }

    private function validModel(): Model
    {
        return new class implements Model {
            public function init(): ?Cmd
            {
                return null;
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                return UpdateResult::keep($this);
            }

            public function render(Frame $frame, RenderContext $context): void
            {
            }
        };
    }
}

final class NonModelReducer implements Reducer
{
    public function update(Message $message, Rect $area): UpdateResult
    {
        return UpdateResult::keep($this);
    }
}

final class CancellationProbe implements RunningProcess
{
    public bool $stopped = false;

    public function __construct(private readonly EffectId $effectId)
    {
    }

    public function pump(): array
    {
        return [];
    }

    public function map(Message $message): Message
    {
        return $message;
    }

    public function isRunning(): bool
    {
        return !$this->stopped;
    }

    public function stop(): void
    {
        $this->stopped = true;
    }

    public function id(): ?EffectId
    {
        return $this->effectId;
    }
}
