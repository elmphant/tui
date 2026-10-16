<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderPass;
use Elmphant\Tui\Rendering\Screen;
use Elmphant\Tui\Runtime\Model;
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Runtime\TerminalSession;
use Elmphant\Tui\Terminal\Ansi;
use Elmphant\Tui\Testing\VirtualTerminal;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use SplQueue;

final class ProgramKittyTest extends TestCase
{
    #[Test]
    public function kittyQuerySentOnStartup(): void
    {
        $model = new class implements Model {
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

            public function render(Frame $frame, RenderPass $pass): void
            {
            }
        };

        $terminal = new VirtualTerminal(80, 24);
        $terminal->simulateInput('q');

        Program::new($model)->withTerminal($terminal)->start();

        $output = $terminal->output();
        $this->assertStringContainsString(Ansi::QUERY_KITTY_KEYBOARD, $output, 'Should send Kitty query on startup');
    }

    #[Test]
    public function aKeyPressAndItsReleaseMoveTheModelOnce(): void
    {
        $model = new class implements Model {
            /**
             * @var list<string>
             */
            public array $keys = [];

            public function init(): ?Cmd
            {
                return null;
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                if (!$message instanceof KeyMsg) {
                    return new UpdateResult($this);
                }
                if ($message->key === 'q') {
                    return new UpdateResult($this, Cmd::quit());
                }
                $next = clone $this;
                $next->keys = [...$this->keys, $message->eventType->name];

                return new UpdateResult($next);
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
            }
        };

        $terminal = new VirtualTerminal(80, 24);
        // Kitty answers the capability query, then reports j pressed and j released.
        $terminal->simulateInput("\x1b[?1u\x1b[106;1:1u\x1b[106;1:3uq");

        $result = Program::new($model)->withTerminal($terminal)->start();

        self::assertSame(['Press'], $result->keys);
    }

    #[Test]
    public function kittyProtocolEnabledWhenTerminalResponds(): void
    {
        $model = new class implements Model {
            public bool $receivedKey = false;

            public function init(): ?Cmd
            {
                return null;
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                if ($message instanceof KeyMsg && $message->key === 'a' && $message->ctrl) {
                    $new = clone $this;
                    $new->receivedKey = true;

                    return new UpdateResult($new, Cmd::quit());
                }

                return new UpdateResult($this);
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
            }
        };

        $terminal = new VirtualTerminal(80, 24);
        $terminal->simulateInput("\x1b[?1u\x1b[97;5u");

        $result = Program::new($model)->withTerminal($terminal)->start();

        $output = $terminal->output();
        $this->assertStringContainsString(Ansi::ENABLE_KITTY_KEYBOARD, $output, 'Should enable Kitty after response');
        $this->assertTrue($result->receivedKey, 'Kitty Ctrl+A sequence should be parsed');
    }

    #[Test]
    public function kittyProtocolDisabledOnTeardown(): void
    {
        $model = new class implements Model {
            public function init(): ?Cmd
            {
                return null;
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                if ($message instanceof KeyMsg && $message->key === 'a' && $message->ctrl) {
                    return new UpdateResult($this, Cmd::quit());
                }

                return new UpdateResult($this);
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
            }
        };

        $terminal = new VirtualTerminal(80, 24);
        $terminal->simulateInput("\x1b[?1u\x1b[97;5u");

        Program::new($model)->withTerminal($terminal)->start();

        $output = $terminal->output();
        $this->assertStringContainsString(Ansi::DISABLE_KITTY_KEYBOARD, $output, 'Should disable Kitty on teardown');
    }

    #[Test]
    public function kittyProtocolNotEnabledWithoutResponse(): void
    {
        $model = new class implements Model {
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

            public function render(Frame $frame, RenderPass $pass): void
            {
            }
        };

        $terminal = new VirtualTerminal(80, 24);
        $terminal->simulateInput('q');

        Program::new($model)->withTerminal($terminal)->start();

        $output = $terminal->output();
        $this->assertStringNotContainsString(Ansi::ENABLE_KITTY_KEYBOARD, $output, 'Should not enable without response');
        $this->assertStringNotContainsString(Ansi::DISABLE_KITTY_KEYBOARD, $output, 'Should not disable if never enabled');
    }

    #[Test]
    public function inputKeyReachesModel(): void
    {
        $model = new class implements Model {
            public ?string $lastKey = null;

            public function init(): ?Cmd
            {
                return null;
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                if ($message instanceof KeyMsg && is_string($message->key)) {
                    $new = clone $this;
                    $new->lastKey = $message->key;
                    if ($message->key === 'q') {
                        return new UpdateResult($new, Cmd::quit());
                    }

                    return new UpdateResult($new);
                }

                return new UpdateResult($this);
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
            }
        };

        $terminal = new VirtualTerminal(80, 24);
        $terminal->simulateInput('xq');

        $result = Program::new($model)->withTerminal($terminal)->start();

        $this->assertSame('q', $result->lastKey, 'Input bytes must reach the model via update()');
    }

    #[Test]
    public function suspendRestorationDisablesKittyWhenActive(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $program = Program::new(new class implements Model {
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
        });

        $session = new TerminalSession($terminal, false, false);
        $session->start();
        $session->enableKittyProtocol();
        (new ReflectionProperty(Program::class, 'terminalSession'))->setValue($program, $session);

        $method = new ReflectionMethod(Program::class, 'restoreTerminalForSuspension');
        $method->invoke($program);

        $output = $terminal->output();
        $this->assertStringContainsString(Ansi::DISABLE_KITTY_KEYBOARD, $output, 'Suspension must disable Kitty protocol if active');
    }

    #[Test]
    public function suspendRestorationSkipsKittyDisableWhenInactive(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $program = Program::new(new class implements Model {
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
        });

        $terminalProperty = new ReflectionProperty(Program::class, 'terminal');
        $terminalProperty->setValue($program, $terminal);

        $method = new ReflectionMethod(Program::class, 'restoreTerminalForSuspension');
        $method->invoke($program);

        $output = $terminal->output();
        $this->assertStringNotContainsString(Ansi::DISABLE_KITTY_KEYBOARD, $output, 'Suspension must not disable Kitty if it was never active');
    }

    #[Test]
    public function resumeReEnablesKittyWhenPreviouslyActive(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $program = Program::new(new class implements Model {
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
        });

        $session = new TerminalSession($terminal, false, false);
        $session->start();
        $session->enableKittyProtocol();
        $session->suspend();

        $terminalProperty = new ReflectionProperty(Program::class, 'terminal');
        $terminalProperty->setValue($program, $terminal);
        $screenProperty = new ReflectionProperty(Program::class, 'screen');
        $screenProperty->setValue($program, new Screen($terminal));
        $messageQueueProperty = new ReflectionProperty(Program::class, 'messageQueue');
        $messageQueueProperty->setValue($program, new SplQueue());
        (new ReflectionProperty(Program::class, 'terminalSession'))->setValue($program, $session);

        $method = new ReflectionMethod(Program::class, 'resume');
        $method->invoke($program);
        (new ReflectionMethod(Program::class, 'cancelEventLoopWatchers'))->invoke($program);

        $output = $terminal->output();
        $this->assertStringContainsString(Ansi::ENABLE_KITTY_KEYBOARD, $output, 'Resume must re-enable Kitty protocol if it was active before suspend');
        $session->close();
    }

    #[Test]
    public function kittyEnableCalledOnlyOnceEvenIfResponseArrivesTwice(): void
    {
        $model = new class implements Model {
            public function init(): ?Cmd
            {
                return null;
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                if ($message instanceof KeyMsg && $message->key === 'a' && $message->ctrl) {
                    return new UpdateResult($this, Cmd::quit());
                }

                return new UpdateResult($this);
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
            }
        };

        $terminal = new VirtualTerminal(80, 24);
        $terminal->simulateInput("\x1b[?1u\x1b[?5u\x1b[97;5u");

        Program::new($model)->withTerminal($terminal)->start();

        $output = $terminal->output();
        $enableCount = substr_count($output, Ansi::ENABLE_KITTY_KEYBOARD);
        $this->assertSame(1, $enableCount, 'Enable should fire exactly once even with duplicate responses');
    }
}
