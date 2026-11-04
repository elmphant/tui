<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Testing;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Cmd\FiberCmd;
use Elmphant\Tui\Core\EffectId;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Tests\Runtime\FiberDone;
use Elmphant\Tui\Tests\Runtime\FiberLoggingModel;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class HarnessRunsFibersTest extends TestCase
{
    #[Test]
    public function aFiberWaitsUntilTheTestRunsIt(): void
    {
        $driven = ModelHarness::for(new FiberLoggingModel(Cmd::fiber(static fn (): Message => new FiberDone('loaded'), EffectId::named('load'))))->init();

        self::assertCount(1, $driven->issued(FiberCmd::class));
        self::assertSame([], $driven->model()->log);
        self::assertSame(['loaded'], $driven->runFibers()->model()->log);
    }

    #[Test]
    public function aMappedFiberIsAnsweredThroughItsWrap(): void
    {
        $driven = ModelHarness::for(new FiberLoggingModel(Cmd::fiber(static fn (): Message => new FiberDone('loaded'))->map(
            static fn (Message $message): Message => new FiberDone('wrapped'),
        )))->init();

        self::assertSame(['wrapped'], $driven->runFibers()->model()->log);
    }

    #[Test]
    public function aCancelledFiberDoesNotRun(): void
    {
        $driven = ModelHarness::for(new FiberLoggingModel(Cmd::batch(
            Cmd::fiber(static fn (): Message => new FiberDone('late'), EffectId::named('load')),
            Cmd::cancel(EffectId::named('load')),
        )))->init();

        self::assertSame([], $driven->runFibers()->model()->log);
    }

    #[Test]
    public function aFiberThatThrowsIsAnsweredWithAFiberError(): void
    {
        $driven = ModelHarness::for(new FiberLoggingModel(Cmd::fiber(static fn (): never => throw new RuntimeException('offline'), EffectId::named('load'))))->init();

        self::assertSame(['error from load: fiber offline'], $driven->runFibers()->model()->log);
    }

    #[Test]
    public function aFailedFiberIsAFiberError(): void
    {
        $driven = ModelHarness::for(new FiberLoggingModel(Cmd::fiber(static fn (): ?Message => null, EffectId::named('load'))))
            ->init()
            ->fail('load', 'offline');

        self::assertSame(['error from load: fiber offline'], $driven->model()->log);
    }
}
