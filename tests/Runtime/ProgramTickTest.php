<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use ArrayObject;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Rendering\Context\RenderPass;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Runtime\Model;
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Testing\TestClock;
use Elmphant\Tui\Tests\Support\GuardedTerminal;
use Elmphant\Tui\Tests\Support\Ping;
use Elmphant\Tui\Tests\Support\ScriptedModel;
use Elmphant\Tui\Tests\Support\TestMessage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;

final class ProgramTickTest extends TestCase
{
    #[Test]
    public function aChangeWaitsForTheNextFrameAndIsDrawnTheMomentItIsDue(): void
    {
        $clock = new TestClock(10.0);
        $terminal = GuardedTerminal::of(20, 3);
        $frames = new ArrayObject();
        $framesAtEachCheck = [];
        EventLoop::delay(0.05, static function () use ($clock, $terminal): void {
            $clock->advance(0.25);
            $terminal->simulateInput('p');
        });
        EventLoop::delay(0.15, static function () use ($frames, &$framesAtEachCheck, $clock): void {
            $framesAtEachCheck[] = \count($frames);
            $clock->advance(0.25);
        });
        EventLoop::delay(0.45, static function () use ($frames, &$framesAtEachCheck, $terminal): void {
            $framesAtEachCheck[] = \count($frames);
            $terminal->closeInput();
        });

        Program::new(new ScriptedModel(
            render: static fn () => $frames->append(true),
            onMessage: static fn (Message $message): ?Cmd => $message instanceof KeyMsg ? Cmd::message(new Ping()) : null,
        ))->withTerminal($terminal)->withClock($clock)->withFps(2.0)->start();

        self::assertSame([1, 2], $framesAtEachCheck);
    }

    #[Test]
    public function aTickAskedForWhileAnUpdateRunsWaitsForThatUpdateToFinish(): void
    {
        $model = new TicksFromInsideAnUpdateModel(new ArrayObject());
        $program = Program::new($model)->withTerminal(GuardedTerminal::of(20, 3));
        $model->program = $program;

        $program->start();

        self::assertSame(['start first', 'end first', 'start second', 'end second'], $model->order->getArrayCopy());
    }
}

final class TicksFromInsideAnUpdateModel implements Model
{
    /**
     * @var Program<self>|null
     */
    public ?Program $program = null;

    /**
     * @param ArrayObject<int, string> $order
     */
    public function __construct(public readonly ArrayObject $order)
    {
    }

    public function init(): ?Cmd
    {
        return Cmd::batch(Cmd::message(new TestMessage('first')), Cmd::message(new TestMessage('second')));
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        if (!$message instanceof TestMessage) {
            return new UpdateResult($this);
        }

        $this->order->append('start ' . $message->value);
        $this->program?->tick();
        $this->order->append('end ' . $message->value);

        return new UpdateResult($this, $message->value === 'second' ? Cmd::quit() : null);
    }

    public function render(Frame $frame, RenderPass $pass): void
    {
    }
}
