<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Model;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Message\PasteMsg;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Testing\VirtualTerminal;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProgramTerminalInjectionTest extends TestCase
{
    #[Test]
    public function startupUsesNonQueryTerminalSizeWithoutSendingACprProbe(): void
    {
        $model = new class implements Model {
            public function init(): ?Cmd
            {
                return Cmd::quit();
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                return UpdateResult::keep($this);
            }

            public function render(Frame $frame, RenderContext $context): void
            {
            }
        };
        $terminal = new VirtualTerminal(97, 31);

        Program::new($model)->withTerminal($terminal)->start();

        self::assertSame(1, $terminal->sizeCallCount());
        self::assertSame(0, $terminal->probeSizeCallCount());
    }

    #[Test]
    public function initQuitExitsImmediately(): void
    {
        $model = new class implements Model {
            public function init(): ?Cmd
            {
                return Cmd::quit();
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                return new UpdateResult($this);
            }

            public function render(Frame $frame, RenderContext $context): void
            {
            }
        };

        $terminal = new VirtualTerminal(80, 24);
        $start = microtime(true);
        $result = Program::new($model)->withTerminal($terminal)->start();
        $elapsed = microtime(true) - $start;

        $this->assertInstanceOf(Model::class, $result);
        $this->assertLessThan(1.0, $elapsed, 'Cmd::quit() from init() should exit immediately');
    }

    #[Test]
    public function modelReceivesInputAndQuits(): void
    {
        $model = new class implements Model {
            public bool $receivedKey = false;

            public function init(): ?Cmd
            {
                return null;
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                if ($message instanceof KeyMsg) {
                    $new = clone $this;
                    $new->receivedKey = true;

                    return new UpdateResult($new, Cmd::quit());
                }

                return new UpdateResult($this);
            }

            public function render(Frame $frame, RenderContext $context): void
            {
            }
        };

        $terminal = new VirtualTerminal(80, 24);
        $terminal->simulateInput('x');
        $start = microtime(true);
        $result = Program::new($model)->withTerminal($terminal)->start();
        $elapsed = microtime(true) - $start;

        $this->assertTrue($result->receivedKey);
        $this->assertLessThan(2.0, $elapsed);
    }

    #[Test]
    public function idleAppStillReceivesInput(): void
    {
        $model = new class implements Model {
            public bool $receivedKey = false;

            public function init(): ?Cmd
            {
                return null;
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                if ($message instanceof KeyMsg && $message->key === 'q') {
                    return new UpdateResult($this, Cmd::quit());
                }

                return new UpdateResult($this);
            }

            public function render(Frame $frame, RenderContext $context): void
            {
                $frame->putString(0, 0, 'Press q to quit');
            }
        };

        $terminal = new VirtualTerminal(80, 24);

        \Revolt\EventLoop::delay(0.1, function () use ($terminal) {
            $terminal->simulateInput('q');
        });

        $start = microtime(true);
        Program::new($model)->withTerminal($terminal)->start();
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(2.0, $elapsed, 'Idle app should respond to input promptly');
    }

    #[Test]
    public function modelReceivesPasteMsg(): void
    {
        $model = new class implements Model {
            public string $pastedContent = '';

            public function init(): ?Cmd
            {
                return null;
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                if ($message instanceof PasteMsg) {
                    $new = clone $this;
                    $new->pastedContent = $message->content;

                    return new UpdateResult($new, Cmd::quit());
                }

                return new UpdateResult($this);
            }

            public function render(Frame $frame, RenderContext $context): void
            {
            }
        };

        $terminal = new VirtualTerminal(80, 24);
        $terminal->simulateInput("\x1b[200~hello paste\x1b[201~");
        $start = microtime(true);
        $result = Program::new($model)->withTerminal($terminal)->start();
        $elapsed = microtime(true) - $start;

        $this->assertSame('hello paste', $result->pastedContent);
        $this->assertLessThan(2.0, $elapsed);
    }
}
