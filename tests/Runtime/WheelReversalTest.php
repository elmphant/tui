<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Model;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseButton;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
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
    public function reversingTheWheelDropsTheScrollingThatHasNotHappenedYet(): void
    {
        $program = $this->program();

        $this->enqueueWheel($program, MouseAction::WheelDown, 5);
        $this->enqueueWheel($program, MouseAction::WheelUp, 1);

        self::assertSame(
            [MouseAction::WheelUp],
            $this->wheelActions($program),
            'A backlog of down-scrolling the user has already reversed must not still be applied',
        );
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
    public function aWheelEventReachesTheQueueLikeAnyOther(): void
    {
        $program = $this->program();

        $this->enqueueWheel($program, MouseAction::WheelDown, 1);

        self::assertCount(1, $this->queue($program), 'Merging must not swallow the first event of a run');
    }

    #[Test]
    public function aFlickBecomesOneMessageCarryingEveryNotch(): void
    {
        $program = $this->program();

        $this->enqueueWheel($program, MouseAction::WheelDown, 70);

        $wheels = $this->wheels($program);

        self::assertCount(1, $wheels, 'The terminal splits one gesture into a notch per event; the queue must not grow with them');
        self::assertSame(70, $wheels[0]->repeat, 'Every notch is still a row the user asked for');
    }

    #[Test]
    public function aReversalStartsANewRunRatherThanAddingToTheOldOne(): void
    {
        $program = $this->program();

        $this->enqueueWheel($program, MouseAction::WheelDown, 3);
        $this->enqueueWheel($program, MouseAction::WheelUp, 2);

        $wheels = $this->wheels($program);

        self::assertCount(1, $wheels);
        self::assertSame(MouseAction::WheelUp, $wheels[0]->action);
        self::assertSame(2, $wheels[0]->repeat);
    }

    #[Test]
    public function aClickBetweenTwoRunsKeepsThemApart(): void
    {
        $program = $this->program();
        $queue = $this->queue($program);

        $this->enqueueWheel($program, MouseAction::WheelDown, 2);
        $queue->enqueue(new MouseMsg(1, 1, MouseAction::Press, MouseButton::Left));
        $this->enqueueWheel($program, MouseAction::WheelDown, 3);

        $wheels = $this->wheels($program);

        self::assertCount(2, $wheels, 'Scrolling either side of a click is two gestures, not one');
        self::assertSame([2, 3], [$wheels[0]->repeat, $wheels[1]->repeat]);
    }

    #[Test]
    public function reversingLeavesEverythingThatIsNotWheelingAlone(): void
    {
        $program = $this->program();
        $queue = $this->queue($program);

        $this->enqueueWheel($program, MouseAction::WheelDown, 2);
        $queue->enqueue(new TestMessage('keep me'));
        $this->enqueueWheel($program, MouseAction::WheelUp, 1);

        $kept = [];
        foreach ($queue as $message) {
            if ($message instanceof TestMessage) {
                $kept[] = $message->value;
            }
        }

        self::assertSame(['keep me'], $kept);
    }

    #[Test]
    public function aClickIsNotWheelingAndSurvivesAReversal(): void
    {
        $program = $this->program();
        $queue = $this->queue($program);

        $this->enqueueWheel($program, MouseAction::WheelDown, 2);
        $queue->enqueue(new MouseMsg(1, 1, MouseAction::Press, MouseButton::Left));
        $this->enqueueWheel($program, MouseAction::WheelUp, 1);

        $actions = [];
        foreach ($queue as $message) {
            if ($message instanceof MouseMsg) {
                $actions[] = $message->action;
            }
        }

        self::assertSame([MouseAction::Press, MouseAction::WheelUp], $actions);
    }

    /**
     * @return Program<Model>
     */
    private function program(): Program
    {
        $program = Program::new($this->idleModel());
        (new ReflectionProperty(Program::class, 'messageQueue'))->setValue($program, new SplQueue());

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

    /**
     * @param Program<Model> $program
     *
     * @return list<MouseAction>
     */
    private function wheelActions(Program $program): array
    {
        $actions = [];
        foreach ($this->queue($program) as $message) {
            if ($message instanceof MouseMsg && ($message->action === MouseAction::WheelUp || $message->action === MouseAction::WheelDown)) {
                $actions[] = $message->action;
            }
        }

        return $actions;
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

            public function render(Frame $frame, RenderContext $context): void
            {
            }
        };
    }
}
