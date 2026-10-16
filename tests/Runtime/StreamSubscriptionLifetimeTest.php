<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderPass;
use Elmphant\Tui\Runtime\Model;
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Runtime\ProgramState;
use Elmphant\Tui\Testing\VirtualTerminal;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use RuntimeException;

final class StreamSubscriptionLifetimeTest extends TestCase
{
    #[Test]
    public function aReaderMayCloseTheStreamItJustFoundAtItsEnd(): void
    {
        [$read, $write] = self::streamPair();
        $model = new class($read) implements Model {
            /**
             * @param resource $stream
             */
            public function __construct(private readonly mixed $stream)
            {
            }

            public function init(): ?Cmd
            {
                return Cmd::batch(
                    Cmd::listen($this->stream, static function (mixed $stream): iterable {
                        // What a client does when its peer hangs up: read, find
                        // nothing, tidy up its own handle.
                        if ((string)fread($stream, 64) === '') {
                            fclose($stream);
                        }

                        return [];
                    }),
                    Cmd::after(0.2, static fn (): Cmd => Cmd::quit()),
                );
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                return new UpdateResult($this);
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
            }
        };
        $program = Program::new($model)->withTerminal(new VirtualTerminal(80, 24));

        EventLoop::delay(0.02, static function () use ($write): void {
            fclose($write);
        });

        $program->start();

        self::assertSame(ProgramState::Stopped, $program->state());
    }

    /**
     * @return array{resource, resource}
     */
    private static function streamPair(): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            throw new RuntimeException('cannot make a socket pair');
        }
        stream_set_blocking($pair[0], false);
        stream_set_blocking($pair[1], false);

        return [$pair[0], $pair[1]];
    }
}
