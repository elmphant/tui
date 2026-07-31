<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use Elmphant\Tui\Core\EffectId;
use Elmphant\Tui\Message\ErrorMsg;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Message\ProcessFinishedMsg;
use Elmphant\Tui\Message\ProcessOutputMsg;
use Elmphant\Tui\Message\ProcessStream;
use Elmphant\Tui\Runtime\RunningStreamingProcess;
use Elmphant\Tui\Tests\Support\MessageEnvelope;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;

final class RunningStreamingProcessTest extends TestCase
{
    #[Test]
    public function pumpWrapsEveryOutputAndFinishMessage(): void
    {
        $process = $this->createMock(Process::class);
        $process->method('getIncrementalOutput')->willReturn('abcd');
        $process->method('getIncrementalErrorOutput')->willReturn('ef');
        $process->method('isRunning')->willReturn(false);
        $process->method('getExitCode')->willReturn(0);
        $process->method('isSuccessful')->willReturn(true);
        $wrap = static fn (Message $message): Message => new MessageEnvelope($message);
        $running = new RunningStreamingProcess($process, null, 2, $wrap);

        $messages = $running->pump();

        self::assertCount(4, $messages);
        self::assertInstanceOf(ProcessOutputMsg::class, self::envelope($messages[0])->inner);
        self::assertInstanceOf(ProcessOutputMsg::class, self::envelope($messages[1])->inner);
        self::assertInstanceOf(ProcessOutputMsg::class, self::envelope($messages[2])->inner);
        self::assertInstanceOf(ProcessFinishedMsg::class, self::envelope($messages[3])->inner);
    }

    #[Test]
    public function mapWrapsErrorsReportedByTheProgram(): void
    {
        $process = $this->createMock(Process::class);
        $wrap = static fn (Message $message): Message => new MessageEnvelope($message);
        $running = new RunningStreamingProcess($process, null, wrap: $wrap);

        $message = $running->map(new ErrorMsg(new RuntimeException('pump failed'), 'effect'));

        self::assertInstanceOf(ErrorMsg::class, self::envelope($message)->inner);
    }

    #[Test]
    public function pumpStreamsIncrementally(): void
    {
        $process = $this->createMock(Process::class);

        // Setup mock sequence:
        // 1. isRunning check (inside first pump) -> true
        // 2. getIncrementalOutput (inside first pump) -> 'chunk1'
        // 3. getIncrementalErrorOutput (inside first pump) -> ''
        // 4. isRunning check (inside first pump - optional optimization check? no, standard pump check) -> true (loop continues? no, pump is single pass usually)

        // Actually pump() logic is:
        // $stdout = getIncrementalOutput();
        // $stderr = getIncrementalErrorOutput();
        // if (!isRunning()) { add FinishedMsg }

        $process->expects($this->exactly(2))
            ->method('getIncrementalOutput')
            ->willReturnOnConsecutiveCalls('chunk1', 'chunk2');

        $process->expects($this->exactly(2))
            ->method('getIncrementalErrorOutput')
            ->willReturn('');

        $process->expects($this->exactly(2))
            ->method('isRunning')
            ->willReturnOnConsecutiveCalls(true, false);

        $process->method('getExitCode')->willReturn(0);
        $process->method('isSuccessful')->willReturn(true);

        $running = new RunningStreamingProcess($process, EffectId::named('stream-test'));

        // First pump
        $messages1 = $running->pump();
        self::assertCount(1, $messages1);
        self::assertInstanceOf(ProcessOutputMsg::class, $messages1[0]);
        self::assertSame('chunk1', $messages1[0]->output);

        // Second pump
        $messages2 = $running->pump();
        self::assertCount(2, $messages2); // chunk2 + finished
        self::assertInstanceOf(ProcessOutputMsg::class, $messages2[0]);
        self::assertSame('chunk2', $messages2[0]->output);
        self::assertInstanceOf(ProcessFinishedMsg::class, $messages2[1]);
    }

    #[Test]
    public function pumpStreamsStdoutAndStderrInterleaved(): void
    {
        $process = $this->createMock(Process::class);
        $process->method('isRunning')->willReturn(true);
        $process->method('getIncrementalOutput')->willReturnOnConsecutiveCalls('out1', '');
        $process->method('getIncrementalErrorOutput')->willReturnOnConsecutiveCalls('', 'err1');

        $running = new RunningStreamingProcess($process, EffectId::named('stream-test'));

        // First pump: gets out1
        $messages1 = $running->pump();
        self::assertCount(1, $messages1);
        self::assertInstanceOf(ProcessOutputMsg::class, $messages1[0]);
        self::assertSame('out1', $messages1[0]->output);
        self::assertSame(ProcessStream::Stdout, $messages1[0]->stream);

        // Second pump: gets err1
        $messages2 = $running->pump();
        self::assertCount(1, $messages2);
        self::assertInstanceOf(ProcessOutputMsg::class, $messages2[0]);
        self::assertSame('err1', $messages2[0]->output);
        self::assertSame(ProcessStream::Stderr, $messages2[0]->stream);
    }

    #[Test]
    public function pumpSendsFinishedWhenProcessExits(): void
    {
        $process = $this->createMock(Process::class);
        $process->method('isRunning')->willReturn(false);
        $process->method('getIncrementalOutput')->willReturn('');
        $process->method('getIncrementalErrorOutput')->willReturn('');
        $process->method('getExitCode')->willReturn(123);
        $process->method('isSuccessful')->willReturn(false);

        $running = new RunningStreamingProcess($process, EffectId::named('fin'));
        $messages = $running->pump();

        self::assertCount(1, $messages);
        self::assertInstanceOf(ProcessFinishedMsg::class, $messages[0]);
        self::assertSame(123, $messages[0]->exitCode);
        self::assertFalse($messages[0]->successful);
        self::assertSame('fin', $messages[0]->processId?->value);
        self::assertFalse($running->isRunning());
    }

    #[Test]
    public function pumpReturnsEmptyWhileRunningAndNoOutput(): void
    {
        $process = $this->createMock(Process::class);
        $process->method('isRunning')->willReturn(true);
        $process->method('getIncrementalOutput')->willReturn('');
        $process->method('getIncrementalErrorOutput')->willReturn('');

        $running = new RunningStreamingProcess($process, null);
        self::assertEmpty($running->pump());
        self::assertTrue($running->isRunning());
    }

    #[Test]
    public function pumpSplitsLargeOutputIntoBoundedMessages(): void
    {
        $process = $this->createMock(Process::class);
        $process->method('getIncrementalOutput')->willReturn('abcdef');
        $process->method('getIncrementalErrorOutput')->willReturn('');
        $process->method('isRunning')->willReturn(true);

        $running = new RunningStreamingProcess($process, null, 2);
        $messages = $running->pump();

        self::assertSame(['ab', 'cd', 'ef'], array_map(
            static function (Message $message): string {
                self::assertInstanceOf(ProcessOutputMsg::class, $message);

                return $message->output;
            },
            $messages,
        ));
    }

    #[Test]
    public function pumpDefaultsToSixtyFourKibibyteChunks(): void
    {
        $process = $this->createMock(Process::class);
        $process->method('getIncrementalOutput')->willReturn(str_repeat('s', 65_537));
        $process->method('getIncrementalErrorOutput')->willReturn('');
        $process->method('isRunning')->willReturn(true);

        $running = new RunningStreamingProcess($process, null);
        $messages = $running->pump();

        self::assertCount(2, $messages);
        self::assertInstanceOf(ProcessOutputMsg::class, $messages[0]);
        self::assertInstanceOf(ProcessOutputMsg::class, $messages[1]);
        self::assertSame(65_536, strlen($messages[0]->output));
        self::assertSame(1, strlen($messages[1]->output));
    }

    #[Test]
    public function pumpClampsTheStdoutChunkSizeToAtLeastOneByte(): void
    {
        $process = $this->createMock(Process::class);
        $process->method('getIncrementalOutput')->willReturn('ab');
        $process->method('getIncrementalErrorOutput')->willReturn('');
        $process->method('isRunning')->willReturn(true);

        $running = new RunningStreamingProcess($process, null, 0);
        $messages = $running->pump();

        self::assertSame(['a', 'b'], array_map(
            static function (Message $message): string {
                self::assertInstanceOf(ProcessOutputMsg::class, $message);

                return $message->output;
            },
            $messages,
        ));
    }

    #[Test]
    public function pumpClampsTheStderrChunkSizeToAtLeastOneByte(): void
    {
        $process = $this->createMock(Process::class);
        $process->method('getIncrementalOutput')->willReturn('');
        $process->method('getIncrementalErrorOutput')->willReturn('cd');
        $process->method('isRunning')->willReturn(true);

        $running = new RunningStreamingProcess($process, null, 0);
        $messages = $running->pump();

        self::assertSame(['c', 'd'], array_map(
            static function (Message $message): string {
                self::assertInstanceOf(ProcessOutputMsg::class, $message);

                return $message->output;
            },
            $messages,
        ));
    }

    #[Test]
    public function pumpClearsConsumedOutputBuffers(): void
    {
        $process = $this->createMock(Process::class);
        $process->method('getIncrementalOutput')->willReturn('out');
        $process->method('getIncrementalErrorOutput')->willReturn('err');
        $process->method('isRunning')->willReturn(true);
        $process->expects(self::once())->method('clearOutput');
        $process->expects(self::once())->method('clearErrorOutput');

        $running = new RunningStreamingProcess($process, null);

        self::assertCount(2, $running->pump());
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

        $running = new RunningStreamingProcess($process, null);
        $messages = $running->pump();

        self::assertCount(1, $messages);
        self::assertInstanceOf(ProcessFinishedMsg::class, $messages[0]);
        self::assertSame(-1, $messages[0]->exitCode);
    }

    private static function envelope(Message $message): MessageEnvelope
    {
        self::assertInstanceOf(MessageEnvelope::class, $message);

        return $message;
    }
}
