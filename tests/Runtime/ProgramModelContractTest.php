<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\Reducer;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\ErrorMsg;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\TickMsg;
use Elmphant\Tui\Rendering\Context\RenderPass;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Runtime\Model;
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Runtime\ProgramState;
use Elmphant\Tui\Testing\VirtualTerminal;
use LogicException;
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

            public function render(Frame $frame, RenderPass $pass): void
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
        $reflection->getProperty('state')->setValue($program, ProgramState::Running);

        $reflection->getMethod('drainMessageQueue')->invoke($program);

        self::assertSame(ProgramState::Stopping, $program->state());
        self::assertSame(10, $updates);
    }

    #[Test]
    public function aReducerThatIsNoModelIsReportedAsABrokenContract(): void
    {
        $model = new class implements Model {
            /**
             * @var list<string>
             */
            public array $reportedErrors = [];

            public function init(): ?Cmd
            {
                return null;
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                if ($message instanceof ErrorMsg) {
                    $this->reportedErrors[] = $message->error::class;

                    return new UpdateResult($this, Cmd::quit());
                }

                if ($message instanceof KeyMsg && $message->key === 'x') {
                    return new UpdateResult(new NonModelReducer());
                }

                return UpdateResult::keep($this);
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
            }
        };

        $terminal = new VirtualTerminal(80, 24);
        $terminal->simulateInput('x');

        Program::new($model)->withTerminal($terminal)->start();

        self::assertSame(
            [LogicException::class],
            $model->reportedErrors,
            'A reducer that is no Model breaks the runtime contract, the way App and ModelHarness say it',
        );
    }
}

final class NonModelReducer implements Reducer
{
    public function update(Message $message, Rect $area): UpdateResult
    {
        return UpdateResult::keep($this);
    }
}
