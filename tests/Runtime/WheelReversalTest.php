<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseButton;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderPass;
use Elmphant\Tui\Runtime\Model;
use Elmphant\Tui\Runtime\PendingWheel;
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Tests\Support\TestMessage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use SplQueue;

final class WheelReversalTest extends TestCase
{
    #[Test]
    public function aStrayNotchAgainstTheGestureNeverReachesTheModel(): void
    {
        $program = $this->program();

        $this->enqueueWheel($program, MouseAction::WheelUp, 3);
        $this->enqueueWheel($program, MouseAction::WheelDown, 1);
        $this->enqueueWheel($program, MouseAction::WheelUp, 2);
        $this->flushWheel($program);

        $wheels = $this->wheels($program);

        self::assertCount(1, $wheels, 'the gesture moves the view once, not three times');
        self::assertSame(MouseAction::WheelUp, $wheels[0]->action);
        self::assertSame(4, $wheels[0]->repeat, 'five up against one down is four up');
    }

    #[Test]
    public function aMessageThatIsNotWheelingIsQueuedUntouched(): void
    {
        $program = $this->program();
        $press = new MouseMsg(3, 4, MouseAction::Press, MouseButton::Left);

        (new ReflectionMethod(Program::class, 'enqueueInputMessage'))->invoke($program, $press);

        self::assertSame([$press], iterator_to_array($this->queue($program)), 'Coalescing is for the wheel alone');
    }

    #[Test]
    public function wheelingWaitsForTheFrameSoItArrivesAsOneMove(): void
    {
        $program = $this->program();

        $this->enqueueWheel($program, MouseAction::WheelDown, 70);

        self::assertCount(0, $this->wheels($program), 'nothing is applied a notch at a time');

        $this->flushWheel($program);
        $wheels = $this->wheels($program);

        self::assertCount(1, $wheels);
        self::assertSame(70, $wheels[0]->repeat, 'Every notch is still a row the user asked for');
    }

    #[Test]
    public function wheelingLeavesEverythingThatIsNotWheelingAlone(): void
    {
        $program = $this->program();
        $queue = $this->queue($program);

        $this->enqueueWheel($program, MouseAction::WheelDown, 2);
        $queue->enqueue(new TestMessage('keep me'));
        $this->enqueueWheel($program, MouseAction::WheelUp, 1);
        $this->flushWheel($program);

        $kept = [];
        foreach ($queue as $message) {
            if ($message instanceof TestMessage) {
                $kept[] = $message->value;
            }
        }

        self::assertSame(['keep me'], $kept);
    }

    #[Test]
    public function aClickIsNotWheelingAndSurvivesAGesture(): void
    {
        $program = $this->program();
        $queue = $this->queue($program);

        $this->enqueueWheel($program, MouseAction::WheelDown, 2);
        $queue->enqueue(new MouseMsg(1, 1, MouseAction::Press, MouseButton::Left));
        $this->enqueueWheel($program, MouseAction::WheelUp, 1);
        $this->flushWheel($program);

        $actions = [];
        foreach ($queue as $message) {
            if ($message instanceof MouseMsg) {
                $actions[] = $message->action;
            }
        }

        self::assertSame([MouseAction::Press, MouseAction::WheelDown], $actions);
    }

    /**
     * @return Program<Model>
     */
    private function program(): Program
    {
        $program = Program::new($this->idleModel());
        (new ReflectionProperty(Program::class, 'messageQueue'))->setValue($program, new SplQueue());
        (new ReflectionProperty(Program::class, 'pendingWheel'))->setValue($program, new PendingWheel());

        return $program;
    }

    /**
     * @param Program<Model> $program
     *
     * @return SplQueue<Message>
     */
    private function queue(Program $program): SplQueue
    {
        /** @var SplQueue<Message> */
        return (new ReflectionProperty(Program::class, 'messageQueue'))->getValue($program);
    }

    /**
     * @param Program<Model> $program
     */
    private function enqueueWheel(Program $program, MouseAction $action, int $times): void
    {
        $enqueue = new ReflectionMethod(Program::class, 'enqueueInputMessage');
        for ($i = 0; $i < $times; ++$i) {
            $enqueue->invoke($program, new MouseMsg(1, 1, $action, MouseButton::None));
        }
    }

    /**
     * @param Program<Model> $program
     */
    private function flushWheel(Program $program): void
    {
        (new ReflectionMethod(Program::class, 'flushWheel'))->invoke($program);
    }

    /**
     * @param Program<Model> $program
     *
     * @return list<MouseMsg>
     */
    private function wheels(Program $program): array
    {
        $wheels = [];
        foreach ($this->queue($program) as $message) {
            if ($message instanceof MouseMsg && ($message->action === MouseAction::WheelUp || $message->action === MouseAction::WheelDown)) {
                $wheels[] = $message;
            }
        }

        return $wheels;
    }

    private function idleModel(): Model
    {
        return new class implements Model {
            public function init(): ?Cmd
            {
                return null;
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                return new UpdateResult($this);
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
            }
        };
    }
}
