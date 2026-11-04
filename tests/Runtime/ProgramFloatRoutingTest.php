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
use Elmphant\Tui\Rendering\Context\FloatMouseMsg;
use Elmphant\Tui\Rendering\Context\PointerFloating;
use Elmphant\Tui\Rendering\Context\RenderPass;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Runtime\Model;
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Runtime\ScreenMode;
use Elmphant\Tui\Testing\VirtualTerminal;
use Elmphant\Tui\Tests\Support\FloatingModel;
use Elmphant\Tui\Tests\Support\GuardedTerminal;
use Elmphant\Tui\Tests\Support\TestPopup;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use RuntimeException;

final class ProgramFloatRoutingTest extends TestCase
{
    #[Test]
    public function aPressOverADrawnFloatArrivesAsAFloatMouseMsg(): void
    {
        $popup = new TestPopup(new Rect(5, 5, 10, 4));
        $model = new FloatingModel($popup);

        self::typeOnceDrawn($model, "\x1b[<0;7;7M");

        self::assertInstanceOf(FloatMouseMsg::class, $model->received);
        self::assertSame($popup, $model->received->popup());
    }

    #[Test]
    public function twoWheelNotchesOverAFloatArriveAsOneMessageWithBothNotches(): void
    {
        $model = new FloatingModel(new TestPopup(new Rect(5, 5, 10, 4)));

        self::typeOnceDrawn($model, "\x1b[<65;7;7M\x1b[<65;7;7M");

        self::assertInstanceOf(FloatMouseMsg::class, $model->received);
        self::assertSame(2, $model->received->mouse->repeat);
        self::assertSame(1, $model->messagesSeen);
    }

    #[Test]
    public function aFrameThatFailedAfterQueueingItsFloatsLeavesThePressToTheFrameOnScreen(): void
    {
        $terminal = GuardedTerminal::of(80, 24);
        $model = new SwapsItsFloatAndFailsModel($terminal, new ArrayObject(), new TestPopup(new Rect(0, 0, 4, 2), 'S'), new TestPopup(new Rect(10, 10, 6, 3), 'N'));
        EventLoop::delay(0.05, static fn () => $terminal->simulateInput('n'));

        try {
            Program::new($model)->withTerminal($terminal)->withScreenMode(ScreenMode::main(mouse: true))->start();
            self::fail('A frame that always fails is given up on');
        } catch (RuntimeException) {
        }

        self::assertCount(1, $model->pressed);
        self::assertInstanceOf(MouseMsg::class, $model->pressed[0]);
    }

    private static function typeOnceDrawn(Model $model, string $input): void
    {
        $terminal = GuardedTerminal::of(80, 24);
        EventLoop::delay(0.05, static fn () => $terminal->simulateInput($input));
        EventLoop::delay(0.15, static fn () => $terminal->closeInput());

        Program::new($model)->withTerminal($terminal)->withScreenMode(ScreenMode::main(mouse: true))->start();
    }
}

final readonly class SwapsItsFloatAndFailsModel implements Model
{
    /**
     * @param ArrayObject<int, MouseMsg|FloatMouseMsg> $pressed
     * @param ArrayObject<int, true>                   $typed
     */
    public function __construct(
        private VirtualTerminal $terminal,
        public ArrayObject $pressed,
        private PointerFloating $shown,
        private PointerFloating $next,
        private bool $failing = false,
        private ArrayObject $typed = new ArrayObject(),
    ) {
    }

    public function init(): ?Cmd
    {
        return null;
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        if ($message instanceof KeyMsg && $message->key === 'n') {
            return new UpdateResult(new self($this->terminal, $this->pressed, $this->next, $this->next, failing: true));
        }
        if ($message instanceof MouseMsg || $message instanceof FloatMouseMsg) {
            $this->pressed->append($message);
        }

        return new UpdateResult($this);
    }

    public function render(Frame $frame, RenderPass $pass): void
    {
        if ($this->failing && $this->typed->count() === 0) {
            $this->typed->append(true);
            $this->terminal->simulateInput("\x1b[<0;12;12M");
        }
        $floating = new FloatingModel($this->shown);
        $floating->throwAfterRenderRoot = $this->failing;
        $floating->render($frame, $pass);
    }
}
