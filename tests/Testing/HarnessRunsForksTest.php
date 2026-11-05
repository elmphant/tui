<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Testing;

use Closure;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Cmd\ForkCmd;
use Elmphant\Tui\Core\EffectId;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Tests\Runtime\ForkLoggingModel;
use Elmphant\Tui\Tests\Runtime\ForkSaid;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class HarnessRunsForksTest extends TestCase
{
    #[Test]
    public function aForkWaitsUntilTheTestRunsIt(): void
    {
        $driven = ModelHarness::for(new ForkLoggingModel(Cmd::fork(static fn (Closure $send): Message => new ForkSaid('loaded'), EffectId::named('load'))))->init();

        self::assertCount(1, $driven->issued(ForkCmd::class));
        self::assertSame([], $driven->model()->log);
        self::assertSame(['loaded'], $driven->runForks()->model()->log);
    }

    #[Test]
    public function runForksAsTheFirstThingRunsTheForkSetupAskedFor(): void
    {
        $driven = ModelHarness::for(new ForkLoggingModel(Cmd::fork(static fn (Closure $send): Message => new ForkSaid('loaded'))))->runForks();

        self::assertSame(['loaded'], $driven->model()->log);
    }

    #[Test]
    public function whatAForkSendsArrivesInOrderBeforeItsAnswer(): void
    {
        $driven = ModelHarness::for(new ForkLoggingModel(Cmd::fork(static function (Closure $send): Message {
            $send(new ForkSaid('first', last: false));
            $send(new ForkSaid('second', last: false));

            return new ForkSaid('done');
        })))->runForks();

        self::assertSame(['first', 'second', 'done'], $driven->model()->log);
    }

    #[Test]
    public function aMappedForkIsAnsweredThroughItsWrap(): void
    {
        $driven = ModelHarness::for(new ForkLoggingModel(Cmd::fork(static fn (Closure $send): Message => new ForkSaid('loaded'))->map(
            static fn (Message $message): Message => new ForkSaid('wrapped'),
        )))->runForks();

        self::assertSame(['wrapped'], $driven->model()->log);
    }

    #[Test]
    public function aCancelledForkDoesNotRun(): void
    {
        $driven = ModelHarness::for(new ForkLoggingModel(Cmd::batch(
            Cmd::fork(static fn (Closure $send): Message => new ForkSaid('late'), EffectId::named('load')),
            Cmd::cancel(EffectId::named('load')),
        )))->runForks();

        self::assertSame([], $driven->model()->log);
    }

    #[Test]
    public function aForkThatThrowsIsAnsweredWithAForkError(): void
    {
        $driven = ModelHarness::for(new ForkLoggingModel(Cmd::fork(static fn (Closure $send): never => throw new RuntimeException('offline'), EffectId::named('load'))))->runForks();

        self::assertSame(['error from load: fork offline'], $driven->model()->log);
    }

    #[Test]
    public function aFailedForkIsAForkError(): void
    {
        $driven = ModelHarness::for(new ForkLoggingModel(Cmd::fork(static fn (Closure $send): ?Message => null, EffectId::named('load'))))
            ->fail('load', 'offline');

        self::assertSame(['error from load: fork offline'], $driven->model()->log);
    }
}
