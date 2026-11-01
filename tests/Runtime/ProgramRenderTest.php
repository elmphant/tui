<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use ArrayObject;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Message\ErrorMsg;
use Elmphant\Tui\Message\ErrorSource;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Rendering\Color;
use Elmphant\Tui\Rendering\Context\RenderPass;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Style;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Terminal\Ansi;
use Elmphant\Tui\Testing\TestClock;
use Elmphant\Tui\Tests\Support\GuardedTerminal;
use Elmphant\Tui\Tests\Support\Ping;
use Elmphant\Tui\Tests\Support\ScriptedModel;
use Elmphant\Tui\Tests\Support\Stop;
use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use RuntimeException;

final class ProgramRenderTest extends TestCase
{
    #[Test]
    public function whatTheModelDrawsReachesTheTerminal(): void
    {
        $terminal = GuardedTerminal::of(20, 3);

        Program::new(new ScriptedModel(
            render: static fn (Frame $frame) => $frame->putString(0, 0, 'HELLO'),
            atStart: Cmd::after(0.05, static fn (): Message => new Stop()),
        ))->withTerminal($terminal)->start();

        self::assertStringContainsString('HELLO', $terminal->output());
    }

    #[Test]
    public function aFrameIsDrawnAtTheTimeOfTheProgramsClock(): void
    {
        $times = new ArrayObject();
        $terminal = GuardedTerminal::of(20, 3);
        EventLoop::delay(0.05, static fn () => $terminal->closeInput());

        Program::new(new ScriptedModel(
            render: static fn (Frame $frame, RenderPass $pass) => $times->append($pass->context->now()),
        ))->withTerminal($terminal)->withClock(new TestClock(5.0))->start();

        self::assertSame(5.0, $times[0]);
    }

    #[Test]
    public function everyFrameStartsBlankInTheThemesBackground(): void
    {
        $background = Color::rgb(50, 50, 50);
        $startOfEachFrame = self::startOfEachFrame(Theme::dark()->withBase(new Style(background: $background)));

        self::assertSame([' ', $background->value], $startOfEachFrame[1]);
    }

    #[Test]
    public function everyFrameStartsBlankWithoutABackgroundWhenTheThemeHasNone(): void
    {
        $startOfEachFrame = self::startOfEachFrame(Theme::dark()->withBase(new Style(foreground: Color::rgb(200, 200, 200))));

        self::assertSame([' ', 0], $startOfEachFrame[1]);
    }

    #[Test]
    public function aFrameWhoseRenderFailsIsNeverShownAndTheModelHearsOfIt(): void
    {
        $terminal = GuardedTerminal::of(20, 3);
        $attempts = new ArrayObject();
        $errors = new ArrayObject();

        Program::new(new ScriptedModel(
            render: static function (Frame $frame) use ($attempts): void {
                $attempts->append(true);
                $frame->putString(0, 0, 'PARTIAL');
                if (\count($attempts) === 1) {
                    throw new RuntimeException('failed half way');
                }
            },
            onMessage: static function (Message $message) use ($errors): ?Cmd {
                if (!$message instanceof ErrorMsg) {
                    return null;
                }
                $errors->append($message->source);

                return Cmd::after(0.05, static fn (): Message => new Stop());
            },
        ))->withTerminal($terminal)->start();

        self::assertSame([ErrorSource::Render], $errors->getArrayCopy());
        self::assertSame(1, substr_count($terminal->output(), 'PARTIAL'), 'only the frame that finished is shown');
    }

    #[Test]
    public function aRenderThatKeepsFailingIsGivenUpOnAfterTenTries(): void
    {
        $attempts = new ArrayObject();

        try {
            Program::new(new ScriptedModel(render: static function () use ($attempts): void {
                $attempts->append(true);

                throw new RuntimeException('render failed');
            }))->withTerminal(GuardedTerminal::of(20, 3))->start();
            self::fail('The program must give up');
        } catch (RuntimeException $error) {
            self::assertSame('render failed', $error->getMessage());
        }

        self::assertCount(10, $attempts);
    }

    #[Test]
    public function aWriteTheTerminalRefusedReachesTheModelAsARenderError(): void
    {
        $terminal = GuardedTerminal::of(20, 3);
        $errors = new ArrayObject();
        $frames = new ArrayObject();
        EventLoop::delay(0.05, static fn () => $terminal->simulateStoppedOutput());

        Program::new(new ScriptedModel(
            render: static function (Frame $frame) use ($frames): void {
                $frames->append(true);
                $frame->putString(0, 0, 'frame ' . \count($frames));
            },
            onMessage: static function (Message $message) use ($errors, $terminal): ?Cmd {
                if (!$message instanceof ErrorMsg) {
                    return null;
                }
                $errors->append($message->source);
                $terminal->resumeOutput();

                return Cmd::quit();
            },
            atStart: Cmd::after(0.1, static fn (): Message => new Ping()),
        ))->withTerminal($terminal)->start();

        self::assertSame([ErrorSource::Render], $errors->getArrayCopy());
    }

    #[Test]
    public function aTerminalThatStallsForManyFramesDoesNotEndTheProgram(): void
    {
        $terminal = GuardedTerminal::of(20, 3);
        $frames = new ArrayObject();
        EventLoop::delay(0.02, static fn () => $terminal->simulateStoppedOutput(stalled: true));
        EventLoop::delay(0.35, static fn () => $terminal->resumeOutput());

        Program::new(new ScriptedModel(
            render: static function (Frame $frame, RenderPass $pass) use ($frames): void {
                $frames->append(true);
                $frame->putString(0, 0, 'frame ' . \count($frames));
                $pass->context->requestRepaint(0.0);
            },
            atStart: Cmd::after(0.4, static fn (): Message => new Stop()),
        ))->withTerminal($terminal)->start();

        self::assertGreaterThan(10, \count($frames));
    }

    #[Test]
    #[RequiresFunction('posix_kill')]
    public function aResizeHandsTheModelAFrameOfTheNewSize(): void
    {
        $terminal = GuardedTerminal::of(80, 24);
        $sizes = new ArrayObject();
        EventLoop::delay(0.05, static function () use ($terminal): void {
            $terminal->resizeTo(100, 30);
            posix_kill(posix_getpid(), SIGWINCH);
        });

        Program::new(new ScriptedModel(
            render: static fn (Frame $frame) => $sizes->append([$frame->width, $frame->height]),
            atStart: Cmd::after(0.2, static fn (): Message => new Stop()),
        ))->withTerminal($terminal)->start();

        self::assertSame([[80, 24], [100, 30]], $sizes->getArrayCopy());
    }

    #[Test]
    #[RequiresFunction('posix_kill')]
    public function aResizeDrawsTheScreenWholeEvenWhenNothingOnItChanged(): void
    {
        $terminal = GuardedTerminal::of(20, 3);
        EventLoop::delay(0.05, static function () use ($terminal): void {
            $terminal->clearOutput();
            posix_kill(posix_getpid(), SIGWINCH);
        });

        Program::new(new ScriptedModel(
            render: static fn (Frame $frame) => $frame->putString(0, 0, 'SAME'),
            atStart: Cmd::after(0.2, static fn (): Message => new Stop()),
        ))->withTerminal($terminal)->start();

        self::assertStringContainsString(Ansi::CURSOR_HOME, $terminal->output());
        self::assertStringContainsString('SAME', $terminal->output());
    }

    #[Test]
    public function anAnimationIsDrawnAgainOnceItsDelayHasPassedAndNotBefore(): void
    {
        $clock = new TestClock(1.0);
        $terminal = GuardedTerminal::of(20, 3);
        $frames = new ArrayObject();
        $framesAtEachCheck = [];
        EventLoop::delay(0.05, static fn () => $clock->advance(0.04));
        EventLoop::delay(0.15, static function () use ($frames, &$framesAtEachCheck, $clock): void {
            $framesAtEachCheck[] = \count($frames);
            $clock->advance(0.01);
        });
        EventLoop::delay(0.25, static function () use ($frames, &$framesAtEachCheck, $terminal): void {
            $framesAtEachCheck[] = \count($frames);
            $terminal->closeInput();
        });

        Program::new(new ScriptedModel(render: static function (Frame $frame, RenderPass $pass) use ($frames): void {
            $frames->append(true);
            $pass->context->requestRepaint(0.05);
        }))->withTerminal($terminal)->withClock($clock)->start();

        self::assertSame([1, 2], $framesAtEachCheck);
    }

    #[Test]
    public function aLoneEscapeArrivesWithoutWaitingForAnotherKey(): void
    {
        $terminal = GuardedTerminal::of(20, 3);
        $keys = new ArrayObject();
        $terminal->simulateInput("\x1b");
        $started = microtime(true);

        Program::new(new ScriptedModel(
            render: static function (): void {
            },
            onMessage: static function (Message $message) use ($keys): ?Cmd {
                if (!$message instanceof KeyMsg) {
                    return null;
                }
                $keys->append($message->key);

                return Cmd::quit();
            },
        ))->withTerminal($terminal)->start();

        self::assertSame(['Escape'], array_map(static fn ($key): string => \is_string($key) ? $key : $key->name, $keys->getArrayCopy()));
        self::assertLessThan(1.0, microtime(true) - $started);
    }

    /**
     * @return list<array{string, int}>
     */
    private static function startOfEachFrame(Theme $theme): array
    {
        $starts = new ArrayObject();

        Program::new(new ScriptedModel(
            render: static function (Frame $frame) use ($starts): void {
                $starts->append([$frame->characters[0], $frame->backgrounds[0]]);
                $frame->putString(0, 0, 'FIRST', background: 7);
            },
            atStart: Cmd::after(0.05, static fn (): Message => new Ping()),
            onMessage: static fn (Message $message): ?Cmd => $message instanceof Ping ? Cmd::after(0.05, static fn (): Message => new Stop()) : null,
        ))->withTerminal(GuardedTerminal::of(20, 3))->withTheme($theme)->start();

        return array_values($starts->getArrayCopy());
    }
}
