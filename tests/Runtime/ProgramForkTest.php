<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use Closure;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\EffectId;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Testing\VirtualTerminal;
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
