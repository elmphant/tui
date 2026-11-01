<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use ArrayObject;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Rendering\Context\RenderPass;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Runtime\Model;
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Tests\Support\GuardedTerminal;
use Elmphant\Tui\Tests\Support\TestMessage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;

final class ProgramAreaTest extends TestCase
{
    #[Test]
    public function everyUpdateIsHandedTheWholeTerminal(): void
    {
        $model = new TerminalAreaRecordingModel(new ArrayObject(), new ArrayObject());

        Program::new($model)->withTerminal(GuardedTerminal::of(120, 40))->start();

        self::assertGreaterThanOrEqual(2, \count($model->areas));
        self::assertEquals(array_fill(0, \count($model->areas), new Rect(0, 0, 120, 40)), $model->areas->getArrayCopy());
    }

    #[Test]
    public function aKeyAndAPressCarryTheAreaTheFrameWasDrawnInto(): void
    {
        $model = new TerminalAreaRecordingModel(new ArrayObject(), new ArrayObject(), inputBeforeQuitting: 2);
        $terminal = GuardedTerminal::of(120, 40);
        EventLoop::delay(0.05, static fn () => $terminal->simulateInput("x\x1b[<0;4;5M"));

        Program::new($model)->withTerminal($terminal)->withMouseTracking()->start();

        self::assertCount(2, $model->input);
        foreach ($model->input as $input) {
            self::assertEquals($model->drawnInto, $input->screen);
        }
        self::assertEquals(new Rect(0, 0, 120, 40), $model->drawnInto);
    }
}

final class TerminalAreaRecordingModel implements Model
{
    public ?Rect $drawnInto = null;

    /**
     * @param ArrayObject<int, Rect>            $areas
     * @param ArrayObject<int, KeyMsg|MouseMsg> $input
     */
    public function __construct(
        public readonly ArrayObject $areas,
        public readonly ArrayObject $input,
        private readonly int $inputBeforeQuitting = 0,
    ) {
    }

    public function init(): ?Cmd
    {
        return Cmd::batch(Cmd::message(new TestMessage('first')), Cmd::message(new TestMessage('second')));
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        $this->areas->append($area);
        if ($message instanceof KeyMsg || $message instanceof MouseMsg) {
            $this->input->append($message);
        }
        $done = $this->inputBeforeQuitting === 0
            ? $message instanceof TestMessage && $message->value === 'second'
            : \count($this->input) === $this->inputBeforeQuitting;

        return new UpdateResult($this, $done ? Cmd::quit() : null);
    }

    public function render(Frame $frame, RenderPass $pass): void
    {
        $this->drawnInto = $frame->area();
    }
}
