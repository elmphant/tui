<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use Closure;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\EffectId;
use Elmphant\Tui\Core\NamesItsEffect;
use Elmphant\Tui\Message\ErrorMsg;
use Elmphant\Tui\Message\ErrorSource;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Message\ProcessResultMsg;
use Elmphant\Tui\Message\TickMsg;
use Elmphant\Tui\Runtime\CommandHandler;
use Elmphant\Tui\Runtime\EffectRunner;
use Elmphant\Tui\Runtime\HttpRequestStarter;
use Elmphant\Tui\Testing\TestClock;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SplQueue;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * What a running effect produces, read off the queue it reports into rather
 * than out of the runner's own state.
 */
final class EffectRunnerTest extends TestCase
{
    /**
     * @var SplQueue<Message>
     */
    private SplQueue $results;

    private TestClock $clock;

    /**
     * @var list<Cmd>
     */
    private array $dispatched = [];

    #[Test]
    public function aTickerSaysNothingUntilItsIntervalHasPassed(): void
    {
        $runner = $this->runner();
        $runner->run(Cmd::tick(1.0), null);

        $runner->fire($this->clock->now());
        self::assertCount(0, $this->results, 'registering a ticker is not the first tick');

        $this->clock->advance(1.0);
        $runner->fire($this->clock->now());

        self::assertInstanceOf(TickMsg::class, $this->results->dequeue());
    }

    #[Test]
    public function askingAgainForATickAlreadyRunningKeepsOneTicker(): void
    {
        $runner = $this->runner();
        $runner->run(Cmd::tick(1.0), null);

        $this->clock->advance(1.0);
        $runner->fire($this->clock->now());
        $runner->run(Cmd::tick(1.0), null);
        $this->clock->advance(1.0);
        $runner->fire($this->clock->now());

        self::assertCount(2, $this->results);
    }

    #[Test]
    public function aTickAtAnotherIntervalRunsBesideTheFirst(): void
    {
        $runner = $this->runner();
        $runner->run(Cmd::tick(1.0), null);
        $runner->run(Cmd::tick(0.5), null);

        $this->clock->advance(1.0);
        $runner->fire($this->clock->now());

        self::assertCount(2, $this->results);
    }

    #[Test]
    public function aTickWrappedForAChildRunsBesideTheHostsOwn(): void
    {
        $runner = $this->runner();
        $runner->run(Cmd::tick(1.0), null);
        $runner->run(Cmd::tick(1.0), static fn (Message $message): Message => $message);

        $this->clock->advance(1.0);
        $runner->fire($this->clock->now());

        self::assertCount(2, $this->results);
    }

    #[Test]
    public function namedTicksRunBesideAnAnonymousOne(): void
    {
        $runner = $this->runner();
        $runner->run(Cmd::tick(1.0, EffectId::named('clock')), null);
        $runner->run(Cmd::tick(1.0), null);
        $runner->run(Cmd::tick(1.0, EffectId::named('poll')), null);

        $this->clock->advance(1.0);
        $runner->fire($this->clock->now());

        self::assertCount(3, $this->results);
    }

    #[Test]
    public function aTickerKeepsFiringOnItsOwnInterval(): void
    {
        $runner = $this->runner();
        $runner->run(Cmd::tick(1.0), null);

        foreach ([1.0, 1.0, 1.0] as $step) {
            $this->clock->advance($step);
            $runner->fire($this->clock->now());
        }

        self::assertCount(3, $this->results);
    }

    #[Test]
    public function aTickerThatSleptThroughSeveralIntervalsFiresOnce(): void
    {
        $runner = $this->runner();
        $runner->run(Cmd::tick(0.1), null);

        $this->clock->advance(5.0);
        $runner->fire($this->clock->now());

        self::assertCount(1, $this->results, 'a suspended program must not wake to a queue of backdated ticks');
    }

    #[Test]
    public function aTickerFiresAtTheMomentItsDeadlineIsReachedNotAfterIt(): void
    {
        $runner = $this->runner();
        $runner->run(Cmd::tick(1.0), null);

        $this->clock->advance(1.0);
        $runner->fire($this->clock->now());

        self::assertCount(1, $this->results);
    }

    #[Test]
    public function aTimerHandsOverWhatItsCallbackAnswered(): void
    {
        $runner = $this->runner();
        $runner->run(Cmd::after(2.0, static fn (): Message => new TickMsg(7.0)), null);

        $this->clock->advance(2.0);
        $runner->fire($this->clock->now());

        $message = $this->results->dequeue();
        self::assertInstanceOf(TickMsg::class, $message);
        self::assertSame(7.0, $message->timestamp);
    }

    #[Test]
    public function aTimerFiresOnceAndIsThenForgotten(): void
    {
        $runner = $this->runner();
        $runner->run(Cmd::after(1.0, static fn (): Message => new TickMsg(1.0)), null);

        $this->clock->advance(5.0);
        $runner->fire($this->clock->now());
        $runner->fire($this->clock->now());

        self::assertCount(1, $this->results);
    }

    #[Test]
    public function aTimerAnsweringWithACommandHandsItBackToBeDispatched(): void
    {
        $runner = $this->runner();
        $runner->run(Cmd::after(1.0, static fn (): Cmd => Cmd::quit()), null);

        $this->clock->advance(1.0);
        $runner->fire($this->clock->now());

        self::assertCount(1, $this->dispatched, 'a command is not a message and cannot go on the queue');
        self::assertCount(0, $this->results);
    }

    #[Test]
    public function aTimerThatThrowsBecomesAnErrorMessage(): void
    {
        $runner = $this->runner();
        $runner->run(Cmd::after(1.0, static fn () => throw new LogicException('no')), null);

        $this->clock->advance(1.0);
        $runner->fire($this->clock->now());

        $message = $this->results->dequeue();
        self::assertInstanceOf(ErrorMsg::class, $message);
        self::assertSame(ErrorSource::Timer, $message->source);
    }

    #[Test]
    public function aTimerThatThrowsDispatchesNothing(): void
    {
        $runner = $this->runner();
        $runner->run(Cmd::after(1.0, static fn () => throw new LogicException('no')), null);

        $this->clock->advance(1.0);
        $runner->fire($this->clock->now());

        self::assertSame([], $this->dispatched);
    }

    #[Test]
    public function aTimerAnsweringWithSomethingElseSaysSoRatherThanFailingQuietly(): void
    {
        $runner = $this->runner();
        $runner->run(Cmd::after(1.0, static fn (): string => 'not a message'), null);

        $this->clock->advance(1.0);
        $runner->fire($this->clock->now());

        self::assertInstanceOf(ErrorMsg::class, $this->results->dequeue());
    }

    #[Test]
    public function aTimerAnsweringWithAnotherTimerKeepsTheChainAlive(): void
    {
        $runner = $this->selfDispatchingRunner();
        $runner->run(Cmd::after(1.0, static fn (): Cmd => Cmd::after(
            1.0,
            static fn (): Message => new TickMsg(2.0),
        )), null);

        $this->clock->advance(1.0);
        $runner->fire($this->clock->now());

        self::assertCount(0, $this->results, 'the first timer answers with a command, not a message');

        $this->clock->advance(1.0);
        $runner->fire($this->clock->now());

        self::assertCount(1, $this->results, 'the chained timer must outlive the pass that scheduled it');
    }

    #[Test]
    public function cancellingASiblingFromATimerCallbackStaysCancelled(): void
    {
        $runner = $this->selfDispatchingRunner();
        $runner->run(Cmd::after(1.0, static fn (): Cmd => Cmd::cancel(EffectId::named('retry'))), null);
        $runner->run(Cmd::after(3.0, static fn (): Message => new TickMsg(3.0), id: EffectId::named('retry')), null);

        $this->clock->advance(1.0);
        $runner->fire($this->clock->now());

        $this->clock->advance(5.0);
        $runner->fire($this->clock->now());

        self::assertCount(0, $this->results, 'a cancellation decided inside a firing pass cannot be undone by it');
    }

    #[Test]
    public function aCancellationBeatsASiblingStillDueInTheSamePass(): void
    {
        $runner = $this->selfDispatchingRunner();
        $runner->run(Cmd::after(1.0, static fn (): Cmd => Cmd::cancel(EffectId::named('retry'))), null);
        $runner->run(Cmd::after(1.0, static fn (): Message => new TickMsg(1.0), id: EffectId::named('retry')), null);

        $this->clock->advance(1.0);
        $runner->fire($this->clock->now());

        self::assertCount(0, $this->results);
    }

    #[Test]
    public function cancellingByNameStopsThatEffectAndLeavesTheRest(): void
    {
        $runner = $this->runner();
        $runner->run(Cmd::tick(1.0, id: EffectId::named('clock')), null);
        $runner->run(Cmd::tick(1.0, id: EffectId::named('spinner')), null);

        $runner->run(Cmd::cancel(EffectId::named('clock')), null);
        $this->clock->advance(1.0);
        $runner->fire($this->clock->now());

        self::assertCount(1, $this->results, 'only the spinner is left to tick');
    }

    #[Test]
    public function cancellingANameNothingAnswersToChangesNothing(): void
    {
        $runner = $this->runner();
        $runner->run(Cmd::tick(1.0, id: EffectId::named('clock')), null);

        $runner->run(Cmd::cancel(EffectId::named('nobody')), null);
        $this->clock->advance(1.0);
        $runner->fire($this->clock->now());

        self::assertCount(1, $this->results);
    }

    #[Test]
    public function anIdIsOneSlotSoStartingUnderItReplacesWhatHeldIt(): void
    {
        $runner = $this->runner();
        $runner->run(Cmd::tick(1.0, id: EffectId::named('clock')), null);
        $runner->run(Cmd::tick(1.0, id: EffectId::named('clock')), null);

        $this->clock->advance(1.0);
        $runner->fire($this->clock->now());

        self::assertCount(1, $this->results, 'two tickers under one name send messages nothing can tell apart');
    }

    #[Test]
    public function anIdNamesOneEffectWhateverKindItIs(): void
    {
        $runner = $this->runner();
        $runner->run(Cmd::tick(1.0, id: EffectId::named('work')), null);
        $runner->run(Cmd::after(1.0, static fn (): Message => new TickMsg(1.0), id: EffectId::named('work')), null);

        $this->clock->advance(1.0);
        $runner->fire($this->clock->now());

        self::assertCount(1, $this->results, 'the timer took the name the ticker held');
    }

    #[Test]
    public function nothingScheduledMeansNoDeadlineToWaitFor(): void
    {
        self::assertNull($this->runner()->nextDelay());
    }

    #[Test]
    public function theDeadlineIsTheSoonestOfEverythingScheduled(): void
    {
        $runner = $this->runner();
        $runner->run(Cmd::tick(5.0), null);
        $runner->run(Cmd::after(2.0, static fn (): ?Message => null), null);

        self::assertSame(2.0, $runner->nextDelay());
    }

    #[Test]
    public function aTickerAloneStillGivesADeadline(): void
    {
        $runner = $this->runner();
        $runner->run(Cmd::tick(3.0), null);

        self::assertSame(3.0, $runner->nextDelay());
    }

    #[Test]
    public function aTimerAloneStillGivesADeadline(): void
    {
        $runner = $this->runner();
        $runner->run(Cmd::after(4.0, static fn (): ?Message => null), null);

        self::assertSame(4.0, $runner->nextDelay());
    }

    #[Test]
    public function aDeadlineAlreadyPastIsNoWaitAtAllRatherThanATimeBefore(): void
    {
        $runner = $this->runner();
        $runner->run(Cmd::tick(1.0), null);

        $this->clock->advance(10.0);

        self::assertSame(0.0, $runner->nextDelay(), 'a negative delay would ask the loop to sleep backwards');
    }

    #[Test]
    public function aProcessRunsAndSaysWhatItPrinted(): void
    {
        $runner = $this->runner();
        $runner->run(Cmd::exec(['echo', 'hello']), null);

        $this->pumpUntilQuiet($runner);

        $message = $this->results->dequeue();
        self::assertInstanceOf(ProcessResultMsg::class, $message);
        self::assertStringContainsString('hello', $message->stdout);
    }

    #[Test]
    public function aProcessIsRunningUntilItHasFinished(): void
    {
        $runner = $this->runner();
        $runner->run(Cmd::exec(['echo', 'hello']), null);

        self::assertTrue($runner->hasRunningProcesses());

        $this->pumpUntilQuiet($runner);

        self::assertFalse($runner->hasRunningProcesses());
    }

    #[Test]
    public function aProcessThatCannotStartBecomesAnErrorMessage(): void
    {
        $runner = $this->runner();
        $runner->run(Cmd::exec(['/definitely/not/a/binary']), null);

        $this->pumpUntilQuiet($runner);

        self::assertFalse($this->results->isEmpty(), 'a command that cannot run must still answer');
    }

    #[Test]
    public function anEffectIdNamesOneRunningProcess(): void
    {
        $runner = $this->runner();
        $runner->run(Cmd::process(['sleep', '30'])->withId(EffectId::named('job')), null);
        $runner->run(Cmd::process(['sleep', '30'])->withId(EffectId::named('job')), null);

        $runner->run(Cmd::cancel(EffectId::named('job')), null);

        self::assertFalse($runner->hasRunningProcesses(), 'the replacement took the name, so cancelling it leaves nothing');
    }

    #[Test]
    public function aProcessRunsWhereItWasToldTo(): void
    {
        $runner = $this->runner();
        $runner->run(Cmd::exec(['pwd'])->withWorkingDirectory('/tmp'), null);

        $this->pumpUntilQuiet($runner);

        $message = $this->results->dequeue();
        self::assertInstanceOf(ProcessResultMsg::class, $message);
        self::assertStringContainsString('tmp', $message->stdout);
    }

    #[Test]
    public function aStreamingProcessThatOutlivesItsTimeoutEndsWithAnErrorUnderItsName(): void
    {
        $runner = $this->runner();
        $runner->run(Cmd::process(['sleep', '5'])->withTimeout(0.2)->withId(EffectId::named('slow')), null);

        $this->pumpUntilQuiet($runner);

        self::assertFalse($runner->hasRunningProcesses());
        $message = $this->results->dequeue();
        self::assertInstanceOf(ErrorMsg::class, $message);
        self::assertInstanceOf(ProcessTimedOutException::class, $message->error);
        self::assertEquals(EffectId::named('slow'), $message->effectId);
    }

    #[Test]
    public function aProcessSeesTheEnvironmentItWasGiven(): void
    {
        $runner = $this->runner();
        $runner->run(Cmd::exec(['sh', '-c', 'echo $GREETING'])->withEnv(['GREETING' => 'hello-env']), null);

        $this->pumpUntilQuiet($runner);

        $message = $this->results->dequeue();
        self::assertInstanceOf(ProcessResultMsg::class, $message);
        self::assertStringContainsString('hello-env', $message->stdout);
    }

    #[Test]
    public function aProcessReadsTheInputItWasGiven(): void
    {
        $runner = $this->runner();
        $runner->run(Cmd::exec(['cat'])->withInput('piped-in'), null);

        $this->pumpUntilQuiet($runner);

        $message = $this->results->dequeue();
        self::assertInstanceOf(ProcessResultMsg::class, $message);
        self::assertStringContainsString('piped-in', $message->stdout);
    }

    #[Test]
    public function anHttpRequestIsUnbufferedUnlessItAsksToBe(): void
    {
        $response = new MockResponse('ok');
        $this->runnerWithHttp(new MockHttpClient($response))
            ->run(Cmd::http('https://example.test/'), null);

        self::assertFalse($response->getRequestOptions()['buffer'], 'streaming needs a response read as it arrives');
    }

    #[Test]
    public function anHttpRequestKeepsABufferChoiceItWasGiven(): void
    {
        $response = new MockResponse('ok');
        $this->runnerWithHttp(new MockHttpClient($response))
            ->run(Cmd::http('https://example.test/')->withOptions(['buffer' => true]), null);

        self::assertTrue($response->getRequestOptions()['buffer']);
    }

    #[Test]
    public function anHttpRequestCarriesTheHeadersAndBodyItWasGiven(): void
    {
        $response = new MockResponse('ok');
        $this->runnerWithHttp(new MockHttpClient($response))->run(
            Cmd::http('https://example.test/', 'POST')->withHeaders(['X-Test' => 'yes'])->withBody('payload'),
            null,
        );

        self::assertContains('X-Test: yes', $response->getRequestOptions()['headers']);
        self::assertSame('payload', $response->getRequestOptions()['body']);
    }

    #[Test]
    public function cancellingOneRunningProcessLeavesTheOthersAlone(): void
    {
        $runner = $this->runner();
        $runner->run(Cmd::process(['sleep', '30'])->withId(EffectId::named('first')), null);
        $runner->run(Cmd::process(['sleep', '30'])->withId(EffectId::named('second')), null);

        $runner->run(Cmd::cancel(EffectId::named('first')), null);
        self::assertTrue($runner->hasRunningProcesses(), 'the second was not asked to stop');

        $runner->run(Cmd::cancel(EffectId::named('second')), null);
        self::assertFalse($runner->hasRunningProcesses());
    }

    #[Test]
    public function shuttingDownStopsWhatWasStillRunning(): void
    {
        $runner = $this->runner();
        $runner->run(Cmd::process(['sleep', '30']), null);
        self::assertTrue($runner->hasRunningProcesses());

        $runner->shutdown();

        self::assertFalse($runner->hasRunningProcesses());
    }

    /**
     * Forgetting a process is not stopping it. Emptying the list satisfies
     * `hasRunningProcesses()` on its own, so the child is asked for directly:
     * one left behind outlives the program that spawned it.
     */
    #[Test]
    public function shuttingDownLeavesNoChildBehind(): void
    {
        if (!function_exists('posix_kill')) {
            self::markTestSkipped('asking whether a pid is still alive needs ext-posix');
        }

        $pidFile = tempnam(sys_get_temp_dir(), 'elmphant-child-');
        self::assertIsString($pidFile);

        $runner = $this->runner();
        // The child reports its own pid, so the test asks the OS about the process
        // the runner actually spawned rather than searching by name.
        $runner->run(Cmd::process(['sh', '-c', 'echo $$ > ' . escapeshellarg($pidFile) . '; sleep 30']), null);

        $pid = self::pidWrittenTo($pidFile);
        self::assertTrue(posix_kill($pid, 0), 'the child must be running before shutdown proves anything');

        $runner->shutdown();

        self::assertFalse(posix_kill($pid, 0), 'a child left running outlives the program that spawned it');
        unlink($pidFile);
    }

    #[Test]
    public function aCommandTheRunnerHasNoEffectForSaysSoRatherThanBeingIgnored(): void
    {
        $this->expectException(LogicException::class);

        $this->runner()->run(new UnknownEffectCmd(), null);
    }

    #[Test]
    public function whatAnEffectProducesIsWrappedOnTheWayOut(): void
    {
        $runner = $this->runner();
        $runner->run(
            Cmd::after(1.0, static fn (): Message => new TickMsg(1.0)),
            static fn (Message $message): Message => new WrappedEffectMessage($message),
        );

        $this->clock->advance(1.0);
        $runner->fire($this->clock->now());

        self::assertInstanceOf(WrappedEffectMessage::class, $this->results->dequeue());
    }

    #[Test]
    public function aCommandTheRuntimeHasNoEffectForGoesToAHandler(): void
    {
        $handler = new SoundHandler();

        $this->runnerWith($handler)->run(new SoundCmd(), null);

        self::assertSame(1, $handler->started);
        self::assertInstanceOf(TickMsg::class, $this->results->dequeue(), 'what a handler emits reaches the model');
    }

    #[Test]
    public function cancellingByNameStopsAHandlersEffect(): void
    {
        $handler = new SoundHandler();
        $runner = $this->runnerWith($handler);
        $chime = EffectId::named('chime');

        $runner->run(new SoundCmd($chime), null);
        $runner->run(Cmd::cancel($chime), null);

        self::assertSame(1, $handler->stopped);
    }

    #[Test]
    public function aHandlersEffectSaysNothingOnceItWasCancelled(): void
    {
        $handler = new SoundHandler();
        $runner = $this->runnerWith($handler);
        $chime = EffectId::named('chime');
        $runner->run(new SoundCmd($chime), null);
        $runner->run(Cmd::cancel($chime), null);
        $this->results->dequeue();
        self::assertNotNull($handler->emit);

        ($handler->emit)(new TickMsg(3.0));

        self::assertCount(0, $this->results);
    }

    #[Test]
    public function aHandlersEffectThatFinishedIsLetGoAndNotStoppedAgain(): void
    {
        $handler = new SoundHandler();
        $runner = $this->runnerWith($handler);
        $runner->run(new SoundCmd(), null);
        $this->results->dequeue();
        self::assertNotNull($handler->finished);
        self::assertNotNull($handler->emit);

        ($handler->finished)();
        ($handler->emit)(new TickMsg(3.0));
        $runner->shutdown();

        self::assertSame([0, 0], [$handler->stopped, count($this->results)]);
    }

    #[Test]
    public function aSecondCommandUnderTheSameNameReplacesTheFirst(): void
    {
        $handler = new SoundHandler();
        $runner = $this->runnerWith($handler);
        $chime = EffectId::named('chime');

        $runner->run(new SoundCmd($chime), null);
        $runner->run(new SoundCmd($chime), null);

        self::assertSame(2, $handler->started);
        self::assertSame(1, $handler->stopped, 'two effects answering to one name send messages no host can tell apart');
    }

    #[Test]
    public function shuttingDownStopsAHandlersEffectTheProgramNeverCancelled(): void
    {
        $handler = new SoundHandler();
        $runner = $this->runnerWith($handler);

        $runner->run(new SoundCmd(), null);
        $runner->shutdown();

        self::assertSame(1, $handler->stopped, 'an effect nobody named still ends with the program');
    }

    #[Test]
    public function aHandlerNeverSeesACommandTheRuntimeHasAnEffectFor(): void
    {
        $handler = new GreedyHandler();
        $runner = $this->runnerWith($handler);

        $runner->run(Cmd::tick(1.0), null);
        $this->clock->advance(1.0);
        $runner->fire($this->clock->now());

        self::assertSame(0, $handler->started, 'a handler that claims everything must not take over the runtime');
        self::assertInstanceOf(TickMsg::class, $this->results->dequeue());
    }

    #[Test]
    public function whatAHandlerEmitsIsWrappedOnTheWayOutToo(): void
    {
        $this->runnerWith(new SoundHandler())->run(
            new SoundCmd(),
            static fn (Message $message): Message => new WrappedEffectMessage($message),
        );

        self::assertInstanceOf(
            WrappedEffectMessage::class,
            $this->results->dequeue(),
            'a child component\'s own effect stays in the namespace its parent mapped it into',
        );
    }

    #[Test]
    public function aHandlerThatCannotStartSaysSoRatherThanTakingTheLoopDown(): void
    {
        $runner = $this->runnerWith(new BrokenHandler());

        $runner->run(new SoundCmd(), null);

        $message = $this->results->dequeue();
        self::assertInstanceOf(ErrorMsg::class, $message);
        self::assertSame(ErrorSource::Command, $message->source);
    }

    #[Test]
    public function emittingFromAHandlerWakesALoopThatWasWaiting(): void
    {
        $woken = 0;
        $runner = new EffectRunner(
            $this->results,
            $this->clock,
            static function (): void {
            },
            static function () use (&$woken): void {
                ++$woken;
            },
            handlers: [new SoundHandler()],
        );

        $runner->run(new SoundCmd(), null);

        self::assertSame(1, $woken, 'a message nobody wakes for waits in the queue until the next keystroke');
    }

    protected function setUp(): void
    {
        /** @var SplQueue<Message> $results */
        $results = new SplQueue();
        $this->results = $results;
        $this->clock = new TestClock(100.0);
        $this->dispatched = [];
    }

    private function runnerWithHttp(HttpClientInterface $client): EffectRunner
    {
        return new EffectRunner(
            $this->results,
            $this->clock,
            static function (): void {
            },
            static function (): void {
            },
            requestStarter: new HttpRequestStarter($client),
        );
    }

    private static function pidWrittenTo(string $file): int
    {
        for ($attempt = 0; $attempt < 200; ++$attempt) {
            $written = trim((string)@file_get_contents($file));

            if (ctype_digit($written)) {
                return (int)$written;
            }

            usleep(10_000);
        }

        self::fail('the child never reported its pid');
    }

    private function runnerWith(CommandHandler ...$handlers): EffectRunner
    {
        return new EffectRunner(
            $this->results,
            $this->clock,
            static function (): void {
            },
            static function (): void {
            },
            handlers: array_values($handlers),
        );
    }

    private function runner(): EffectRunner
    {
        return new EffectRunner(
            $this->results,
            $this->clock,
            function (?Cmd $command): void {
                if ($command !== null) {
                    $this->dispatched[] = $command;
                }
            },
            static function (): void {
            },
        );
    }

    private function selfDispatchingRunner(): EffectRunner
    {
        $runner = null;
        $runner = new EffectRunner(
            $this->results,
            $this->clock,
            static function (?Cmd $command) use (&$runner): void {
                if ($command instanceof Cmd && $runner instanceof EffectRunner) {
                    $runner->run($command, null);
                }
            },
            static function (): void {
            },
        );

        return $runner;
    }

    private function pumpUntilQuiet(EffectRunner $runner): void
    {
        for ($attempt = 0; $attempt < 400 && $runner->hasRunningProcesses(); ++$attempt) {
            $runner->pump();
            usleep(5_000);
        }

        $runner->pump();
    }
}

final readonly class UnknownEffectCmd extends Cmd
{
}

final readonly class SoundCmd extends Cmd implements NamesItsEffect
{
    public function __construct(private ?EffectId $id = null)
    {
    }

    public function effectId(): ?EffectId
    {
        return $this->id;
    }
}

final class BrokenHandler implements CommandHandler
{
    public function handles(Cmd $command): bool
    {
        return true;
    }

    public function start(Cmd $command, Closure $emit, Closure $finished): ?Closure
    {
        throw new LogicException('the sound card is on fire');
    }
}

final class GreedyHandler implements CommandHandler
{
    public int $started = 0;

    public function handles(Cmd $command): bool
    {
        return true;
    }

    public function start(Cmd $command, Closure $emit, Closure $finished): ?Closure
    {
        ++$this->started;

        return null;
    }
}

final class SoundHandler implements CommandHandler
{
    public int $started = 0;

    public int $stopped = 0;

    /**
     * @var Closure(Message): void|null
     */
    public ?Closure $emit = null;

    /**
     * @var Closure(): void|null
     */
    public ?Closure $finished = null;

    public function handles(Cmd $command): bool
    {
        return $command instanceof SoundCmd;
    }

    public function start(Cmd $command, Closure $emit, Closure $finished): ?Closure
    {
        ++$this->started;
        $this->emit = $emit;
        $this->finished = $finished;
        $emit(new TickMsg(2.0));

        return function (): void {
            ++$this->stopped;
        };
    }
}

final readonly class WrappedEffectMessage implements Message
{
    public function __construct(public Message $inner)
    {
    }
}
