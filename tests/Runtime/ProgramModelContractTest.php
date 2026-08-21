<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Model;
use Elmphant\Tui\Core\Reducer;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Message\TickMsg;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Runtime\Program;
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
