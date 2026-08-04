<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Core;

use Closure;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\MappedCmd;
use Elmphant\Tui\Core\Model;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\ErrorMsg;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Message\TickMsg;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Runtime\TickerRecord;
use Elmphant\Tui\Runtime\TimerRecord;
use Elmphant\Tui\Tests\Support\TestMessage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use SplQueue;
use stdClass;
use TypeError;

final class CmdMapTest extends TestCase
{
    #[Test]
    public function mapWrapsTheInnerCommand(): void
    {
        $inner = Cmd::message(new TestMessage('inner'));
        $mapped = $inner->map(static fn (Message $message): Message => $message);

        self::assertInstanceOf(MappedCmd::class, $mapped);
        self::assertSame($inner, $mapped->inner);
    }

    #[Test]
    public function messageIsWrappedBeforeItIsEnqueued(): void
    {
        [$program, $queue] = $this->program();

        $this->dispatch($program, Cmd::message(new TestMessage('inner'))->map($this->wrap('child')));

        $message = $queue->dequeue();
        self::assertInstanceOf(WrappedMapMessage::class, $message);
        self::assertSame('child', $message->scope);
        self::assertInstanceOf(TestMessage::class, $message->inner);
    }

    #[Test]
    public function nestedMapsApplyTheInnerScopeBeforeTheOuterScope(): void
    {
        [$program, $queue] = $this->program();
        $command = Cmd::message(new TestMessage('inner'))
            ->map($this->wrap('first'))
            ->map($this->wrap('second'));

        $this->dispatch($program, $command);

        $outer = $queue->dequeue();
        self::assertInstanceOf(WrappedMapMessage::class, $outer);
        self::assertSame('second', $outer->scope);
        $inner = $outer->inner;
        self::assertInstanceOf(WrappedMapMessage::class, $inner);
        self::assertSame('first', $inner->scope);
    }

    #[Test]
    public function aMapDistributesOverEveryBatchChild(): void
    {
        [$program, $queue] = $this->program();
        $command = Cmd::batch(
            Cmd::message(new TestMessage('one')),
            Cmd::message(new TestMessage('two')),
        )->map($this->wrap('batch'));

        $this->dispatch($program, $command);

        self::assertSame(2, $queue->count());
        foreach ($queue as $message) {
            self::assertInstanceOf(WrappedMapMessage::class, $message);
            self::assertSame('batch', $message->scope);
        }
    }

    #[Test]
    public function aMappedTickerPreservesItsScopeAcrossFires(): void
    {
        [$program, $queue] = $this->program();
        $this->dispatch($program, Cmd::tick(1.0)->map($this->wrap('ticker')));
        /** @var list<TickerRecord> $records */
        $records = (new ReflectionProperty(Program::class, 'tickers'))->getValue($program);
        self::assertCount(1, $records);

        $fire = new ReflectionMethod(Program::class, 'fireTickers');
        $fire->invoke($program, $records[0]->fireAt);
        /** @var list<TickerRecord> $rescheduled */
        $rescheduled = (new ReflectionProperty(Program::class, 'tickers'))->getValue($program);
        $fire->invoke($program, $rescheduled[0]->fireAt);

        self::assertSame(2, $queue->count());
        foreach ($queue as $message) {
            self::assertInstanceOf(WrappedMapMessage::class, $message);
            self::assertInstanceOf(TickMsg::class, $message->inner);
        }
    }

    #[Test]
    public function aMappedTimerWrapsAMessageResult(): void
    {
        [$program, $queue] = $this->program();
        $this->dispatch(
            $program,
            Cmd::after(1.0, static fn (): Message => new TestMessage('timer'))->map($this->wrap('timer')),
        );

        $this->fireOnlyTimer($program);

        self::assertInstanceOf(WrappedMapMessage::class, $queue->dequeue());
    }

    #[Test]
    public function aMappedTimerCarriesItsScopeIntoAReturnedCommand(): void
    {
        [$program, $queue] = $this->program();
        $this->dispatch(
            $program,
            Cmd::after(1.0, static fn (): Cmd => Cmd::message(new TestMessage('timer-command')))
                ->map($this->wrap('timer')),
        );

        $this->fireOnlyTimer($program);

        self::assertInstanceOf(WrappedMapMessage::class, $queue->dequeue());
    }

    #[Test]
    public function aMappedTimerIgnoresNullWithoutEmittingAnError(): void
    {
        [$program, $queue] = $this->program();
        $this->dispatch(
            $program,
            Cmd::after(1.0, static fn (): null => null)->map($this->wrap('timer')),
        );

        $this->fireOnlyTimer($program);

        self::assertTrue($queue->isEmpty());
    }

    #[Test]
    public function aMappedTimerWrapsTheErrorForAnInvalidCallbackResult(): void
    {
        [$program, $queue] = $this->program();
        $command = (new ReflectionMethod(Cmd::class, 'after'))->invoke(
            null,
            1.0,
            static fn (): string => 'invalid',
        );
        self::assertInstanceOf(Cmd::class, $command);
        $this->dispatch($program, $command->map($this->wrap('timer')));

        $this->fireOnlyTimer($program);

        $message = $queue->dequeue();
        self::assertInstanceOf(WrappedMapMessage::class, $message);
        self::assertInstanceOf(ErrorMsg::class, $message->inner);
    }

    #[Test]
    public function aMappedCallWrapsMessagesCommandsAndErrors(): void
    {
        [$program, $queue] = $this->program();
        $wrap = $this->wrap('call');

        $this->dispatch($program, Cmd::call(static fn (): Message => new TestMessage('message'))->map($wrap));
        $this->dispatch($program, Cmd::call(
            static fn (): Cmd => Cmd::message(new TestMessage('command')),
        )->map($wrap));
        $this->dispatch($program, Cmd::call(
            static fn (): never => throw new RuntimeException('failure'),
        )->map($wrap));

        self::assertSame(3, $queue->count());
        $messages = iterator_to_array($queue);
        foreach ($messages as $message) {
            self::assertInstanceOf(WrappedMapMessage::class, $message);
        }
        $error = $messages[2];
        self::assertInstanceOf(WrappedMapMessage::class, $error);
        self::assertInstanceOf(ErrorMsg::class, $error->inner);
    }

    #[Test]
    public function mappedQuitDoesNotInvokeTheWrapper(): void
    {
        [$program] = $this->program();
        $calls = 0;

        $this->dispatch($program, Cmd::quit()->map(function (Message $message) use (&$calls): Message {
            ++$calls;

            return $message;
        }));

        self::assertSame(0, $calls);
        self::assertFalse((new ReflectionProperty(Program::class, 'running'))->getValue($program));
    }

    #[Test]
    public function wrapperMustReturnAMessage(): void
    {
        [$program] = $this->program();
        $command = (new ReflectionMethod(Cmd::class, 'map'))->invoke(
            Cmd::message(new TestMessage('inner')),
            static fn (Message $message): stdClass => new stdClass(),
        );
        self::assertInstanceOf(Cmd::class, $command);

        $this->expectException(TypeError::class);
        $this->dispatch($program, $command);
    }

    /**
     * @return array{Program, SplQueue<Message>}
     */
    private function program(): array
    {
        $model = new class implements Model {
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
        $program = Program::new($model);
        /** @var SplQueue<Message> $queue */
        $queue = new SplQueue();
        (new ReflectionProperty(Program::class, 'messageQueue'))->setValue($program, $queue);
        (new ReflectionProperty(Program::class, 'running'))->setValue($program, true);

        return [$program, $queue];
    }

    private function dispatch(Program $program, Cmd $command): void
    {
        (new ReflectionMethod(Program::class, 'dispatchCommand'))->invoke($program, $command);
    }

    private function fireOnlyTimer(Program $program): void
    {
        /** @var list<TimerRecord> $timers */
        $timers = (new ReflectionProperty(Program::class, 'timers'))->getValue($program);
        self::assertCount(1, $timers);
        (new ReflectionMethod(Program::class, 'fireTimers'))->invoke($program, $timers[0]->fireAt);
    }

    /**
     * @return Closure(Message): Message
     */
    private function wrap(string $scope): Closure
    {
        return static fn (Message $message): Message => new WrappedMapMessage($scope, $message);
    }
}

final readonly class WrappedMapMessage implements Message
{
    public function __construct(
        public string $scope,
        public Message $inner,
    ) {
    }
}
