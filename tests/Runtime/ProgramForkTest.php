<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use Closure;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\EffectId;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Message\ErrorMsg;
use Elmphant\Tui\Message\ForkedWorkFailed;
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Testing\VirtualTerminal;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ProgramForkTest extends TestCase
{
    #[Test]
    #[Group('fork')]
    #[RequiresPhpExtension('pcntl')]
    public function theWorkRunsInAProcessOfItsOwn(): void
    {
        $model = new ForkLoggingModel(Cmd::fork(static fn (Closure $send): Message => new ForkSaid((string)getmypid())));

        Program::new($model)->withTerminal(new VirtualTerminal(20, 2))->start();

        self::assertCount(1, $model->log);
        self::assertNotSame((string)getmypid(), $model->log[0]);
    }

    #[Test]
    #[Group('fork')]
    #[RequiresPhpExtension('pcntl')]
    public function aMessageOfNestedValuesArrivesEqualFromTheChild(): void
    {
        $sent = new ForkReport('packages', [new ForkReport('a/b', [], 1.5), new ForkReport('c/d', [], null)], 2);
        $model = new ForkLoggingModel(Cmd::fork(static fn (Closure $send): Message => $sent));

        Program::new($model)->withTerminal(new VirtualTerminal(20, 2))->start();

        self::assertEquals([$sent], $model->heard);
    }

    #[Test]
    #[Group('fork')]
    #[RequiresPhpExtension('pcntl')]
    public function whatTheChildThrewArrivesWithItsClassAndTrace(): void
    {
        $model = new ForkLoggingModel(Cmd::fork(static fn (Closure $send): never => throw new LogicException('offline'), EffectId::named('load')));

        Program::new($model)->withTerminal(new VirtualTerminal(20, 2))->start();

        $error = $model->heard[0] ?? null;
        self::assertInstanceOf(ErrorMsg::class, $error);
        self::assertInstanceOf(ForkedWorkFailed::class, $error->error);
        self::assertSame(LogicException::class, $error->error->thrownClass);
        self::assertStringContainsString('ProgramForkTest', $error->error->thrownTrace);
    }

    #[Test]
    #[Group('fork')]
    #[RequiresPhpExtension('pcntl')]
    public function aMessageThatCannotLeaveTheChildSendsAnError(): void
    {
        $model = new ForkLoggingModel(Cmd::fork(static fn (Closure $send): Message => new ForkCarrying(static fn (): null => null), EffectId::named('load')));

        Program::new($model)->withTerminal(new VirtualTerminal(20, 2))->start();

        self::assertSame(["error from load: fork Serialization of 'Closure' is not allowed"], $model->log);
    }

    #[Test]
    #[Group('fork')]
    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function aChildThatDiesWithoutAnsweringSendsAnError(): void
    {
        $model = new ForkLoggingModel(Cmd::fork(static function (Closure $send): never {
            posix_kill(posix_getpid(), \SIGKILL);

            exit(1);
        }, EffectId::named('load')));

        Program::new($model)->withTerminal(new VirtualTerminal(20, 2))->start();

        self::assertSame(['error from load: fork Cmd::fork() work ended without an answer'], $model->log);
    }

    #[Test]
    public function theMessageAForkAnswersWithArrives(): void
    {
        $model = new ForkLoggingModel(Cmd::fork(static fn (Closure $send): Message => new ForkSaid('loaded'), EffectId::named('load')));

        Program::new($model)->withTerminal(new VirtualTerminal(20, 2))->start();

        self::assertSame(['loaded'], $model->log);
    }

    #[Test]
    public function whatTheWorkSendsArrivesInOrderBeforeItsAnswer(): void
    {
        $model = new ForkLoggingModel(Cmd::fork(static function (Closure $send): Message {
            $send(new ForkSaid('first', last: false));
            $send(new ForkSaid('second', last: false));

            return new ForkSaid('done');
        }));

        Program::new($model)->withTerminal(new VirtualTerminal(20, 2))->start();

        self::assertSame(['first', 'second', 'done'], $model->log);
    }

    #[Test]
    public function workThatThrowsSendsAnErrorFromItsEffect(): void
    {
        $model = new ForkLoggingModel(Cmd::fork(static fn (Closure $send): never => throw new RuntimeException('offline'), EffectId::named('load')));

        Program::new($model)->withTerminal(new VirtualTerminal(20, 2))->start();

        self::assertSame(['error from load: fork offline'], $model->log);
    }

    #[Test]
    public function workThatAnswersWithSomethingElseThanAMessageSendsAnError(): void
    {
        $model = new ForkLoggingModel(Cmd::fork(static fn (Closure $send): string => 'loaded'));

        Program::new($model)->withTerminal(new VirtualTerminal(20, 2))->start();

        self::assertSame(['error: fork Cmd::fork() work must return a Message or null'], $model->log);
    }
}
