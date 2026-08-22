<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use Elmphant\Tui\Core\EffectId;
use Elmphant\Tui\Message\ErrorMsg;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Message\ProcessResultMsg;
use Elmphant\Tui\Runtime\EffectOutputLimitExceeded;
use Elmphant\Tui\Runtime\RunningExecProcess;
use Elmphant\Tui\Tests\Support\MessageEnvelope;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class RunningExecProcessTest extends TestCase
{
    #[Test]
    public function pumpReturnsEmptyWhileRunning(): void
    {
        $process = new Process(['sleep', '10']);
        $process->start();

        $running = new RunningExecProcess($process, EffectId::named('test'));

        self::assertSame([], $running->pump());
        self::assertTrue($running->isRunning());

        $process->stop(0);
    }

    #[Test]
    public function pumpReturnsResultWhenFinished(): void
    {
        $process = new Process(['echo', 'hello']);
        $process->start();
        $process->wait();

        $running = new RunningExecProcess($process, EffectId::named('test'));
        $messages = $running->pump();

        self::assertCount(1, $messages);
        self::assertInstanceOf(ProcessResultMsg::class, $messages[0]);
        self::assertSame("hello\n", $messages[0]->stdout);
        self::assertSame(0, $messages[0]->exitCode);
        self::assertTrue($messages[0]->successful);
        self::assertSame('test', $messages[0]->effectId?->value);
        self::assertFalse($running->isRunning());
    }

    #[Test]
    public function pumpWrapsSuccessAndErrorMessages(): void
    {
        $wrap = static fn (Message $message): Message => new MessageEnvelope($message);
        $successful = new Process(['echo', 'hello']);
        $successful->start();
        $successful->wait();
        $successMessages = (new RunningExecProcess($successful, null, wrap: $wrap))->pump();

        $limited = $this->createMock(Process::class);
        $limited->method('getIncrementalOutput')->willReturn('12345');
        $limited->method('getIncrementalErrorOutput')->willReturn('');
        $errorMessages = (new RunningExecProcess($limited, null, 4, $wrap))->pump();

        self::assertInstanceOf(MessageEnvelope::class, $successMessages[0]);
        self::assertInstanceOf(ProcessResultMsg::class, $successMessages[0]->inner);
        self::assertInstanceOf(MessageEnvelope::class, $errorMessages[0]);
        self::assertInstanceOf(ErrorMsg::class, $errorMessages[0]->inner);
    }

    #[Test]
    public function pumpCapturesStderr(): void
    {
        $process = new Process(['sh', '-c', 'echo err >&2; exit 1']);
        $process->start();
        $process->wait();

        $running = new RunningExecProcess($process, null);
        $messages = $running->pump();

        self::assertCount(1, $messages);
        self::assertInstanceOf(ProcessResultMsg::class, $messages[0]);
        self::assertSame("err\n", $messages[0]->stderr);
        self::assertSame(1, $messages[0]->exitCode);
        self::assertFalse($messages[0]->successful);
        self::assertNull($messages[0]->effectId);
    }

    #[Test]
    public function pumpStopsCollectionWhenTheOutputLimitIsExceeded(): void
    {
        $process = $this->createMock(Process::class);
        $process->method('getIncrementalOutput')->willReturn('12345');
        $process->method('getIncrementalErrorOutput')->willReturn('');

        $running = new RunningExecProcess($process, EffectId::named('large'), 4);
        $messages = $running->pump();

        self::assertCount(1, $messages);
        self::assertInstanceOf(ErrorMsg::class, $messages[0]);
        self::assertInstanceOf(EffectOutputLimitExceeded::class, $messages[0]->error);
        self::assertSame('large', $messages[0]->effectId?->value);
        self::assertFalse($running->isRunning());
    }

    #[Test]
    public function pumpAccumulatesOutputAcrossPumpsAndClearsConsumedBuffers(): void
    {
        $process = $this->createMock(Process::class);
        $process->method('getIncrementalOutput')->willReturnOnConsecutiveCalls('out-first ', 'out-second', '');
        $process->method('getIncrementalErrorOutput')->willReturnOnConsecutiveCalls('err-first ', 'err-second', '');
        $process->expects(self::exactly(2))->method('clearOutput');
        $process->expects(self::exactly(2))->method('clearErrorOutput');
        $process->method('isRunning')->willReturnOnConsecutiveCalls(true, false);
        $process->method('getExitCode')->willReturn(0);
        $process->method('isSuccessful')->willReturn(true);

        $running = new RunningExecProcess($process, null);

        self::assertSame([], $running->pump());

        $messages = $running->pump();

        self::assertCount(1, $messages);
        self::assertInstanceOf(ProcessResultMsg::class, $messages[0]);
        self::assertSame('out-first out-second', $messages[0]->stdout);
        self::assertSame('err-first err-second', $messages[0]->stderr);
    }

    #[Test]
    public function pumpCountsStderrTowardTheOutputLimit(): void
    {
        $process = $this->createMock(Process::class);
        $process->method('getIncrementalOutput')->willReturn('123');
        $process->method('getIncrementalErrorOutput')->willReturn('45');

        $running = new RunningExecProcess($process, EffectId::named('mixed'), 4);
        $messages = $running->pump();

        self::assertCount(1, $messages);
        self::assertInstanceOf(ErrorMsg::class, $messages[0]);
        self::assertInstanceOf(EffectOutputLimitExceeded::class, $messages[0]->error);
        self::assertFalse($running->isRunning());
    }

    #[Test]
    public function pumpAllowsCombinedOutputExactlyAtTheLimit(): void
    {
        $process = $this->createMock(Process::class);
        $process->method('getIncrementalOutput')->willReturnOnConsecutiveCalls('12', '');
        $process->method('getIncrementalErrorOutput')->willReturnOnConsecutiveCalls('34', '');
        $process->method('isRunning')->willReturn(false);
        $process->method('getExitCode')->willReturn(0);
        $process->method('isSuccessful')->willReturn(true);

        $running = new RunningExecProcess($process, null, 4);
        $messages = $running->pump();

        self::assertCount(1, $messages);
        self::assertInstanceOf(ProcessResultMsg::class, $messages[0]);
        self::assertSame('12', $messages[0]->stdout);
        self::assertSame('34', $messages[0]->stderr);
    }

    #[Test]
    public function pumpDefaultsToAnEightMebibyteOutputLimit(): void
    {
        $atLimit = $this->createMock(Process::class);
        $atLimit->method('getIncrementalOutput')->willReturnOnConsecutiveCalls(str_repeat('x', 8_388_608), '');
        $atLimit->method('getIncrementalErrorOutput')->willReturn('');
        $atLimit->method('isRunning')->willReturn(false);
        $atLimit->method('getExitCode')->willReturn(0);
        $atLimit->method('isSuccessful')->willReturn(true);
        $atLimitMessages = (new RunningExecProcess($atLimit, null))->pump();

        $overLimit = $this->createMock(Process::class);
        $overLimit->method('getIncrementalOutput')->willReturn(str_repeat('x', 8_388_609));
        $overLimit->method('getIncrementalErrorOutput')->willReturn('');
        $overLimitMessages = (new RunningExecProcess($overLimit, null))->pump();

        self::assertCount(1, $atLimitMessages);
        self::assertInstanceOf(ProcessResultMsg::class, $atLimitMessages[0]);
        self::assertCount(1, $overLimitMessages);
        self::assertInstanceOf(ErrorMsg::class, $overLimitMessages[0]);
        self::assertInstanceOf(EffectOutputLimitExceeded::class, $overLimitMessages[0]->error);
        self::assertSame(8_388_608, $overLimitMessages[0]->error->limitBytes);
    }

    #[Test]
    public function pumpReportsExitCodeMinusOneWhenTheExitCodeIsUnknown(): void
    {
        $process = $this->createMock(Process::class);
        $process->method('getIncrementalOutput')->willReturn('');
        $process->method('getIncrementalErrorOutput')->willReturn('');
        $process->method('isRunning')->willReturn(false);
        $process->method('getExitCode')->willReturn(null);
        $process->method('isSuccessful')->willReturn(false);

        $running = new RunningExecProcess($process, null);
        $messages = $running->pump();

        self::assertCount(1, $messages);
        self::assertInstanceOf(ProcessResultMsg::class, $messages[0]);
        self::assertSame(-1, $messages[0]->exitCode);
    }

    #[Test]
    public function theTailFlushedAsTheChildExitsReachesTheResult(): void
    {
        $process = $this->createMock(Process::class);
        $process->method('getIncrementalOutput')->willReturnOnConsecutiveCalls('head ', 'tail');
        $process->method('getIncrementalErrorOutput')->willReturnOnConsecutiveCalls('', 'err-tail');
        $process->method('isRunning')->willReturn(false);
        $process->method('getExitCode')->willReturn(0);
        $process->method('isSuccessful')->willReturn(true);

        $messages = (new RunningExecProcess($process, null))->pump();

        self::assertCount(1, $messages);
        self::assertInstanceOf(ProcessResultMsg::class, $messages[0]);
        self::assertSame('head tail', $messages[0]->stdout);
        self::assertSame('err-tail', $messages[0]->stderr);
    }

    #[Test]
    public function terminationEscalatesToKillAfterAGraceTooShortToStallTheLoop(): void
    {
        $process = $this->createMock(Process::class);
        $process->expects(self::once())->method('stop')->with(0.05);

        (new RunningExecProcess($process, null))->stop();
    }

    #[Test]
    public function stopTerminatesTheUnderlyingProcessOnce(): void
    {
        $process = $this->createMock(Process::class);
        $process->expects(self::once())->method('stop');

        $running = new RunningExecProcess($process, null);
        $running->stop();
        $running->stop();

        self::assertFalse($running->isRunning());
    }
}
