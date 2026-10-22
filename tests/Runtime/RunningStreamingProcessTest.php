<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use Elmphant\Tui\Core\EffectId;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Message\ErrorMsg;
use Elmphant\Tui\Message\ErrorSource;
use Elmphant\Tui\Message\ProcessFinishedMsg;
use Elmphant\Tui\Message\ProcessOutputMsg;
use Elmphant\Tui\Message\ProcessStream;
use Elmphant\Tui\Runtime\RunningStreamingProcess;
use Elmphant\Tui\Tests\Support\MessageEnvelope;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final class RunningStreamingProcessTest extends TestCase
{
    #[Test]
    public function pumpWrapsEveryOutputAndFinishMessage(): void
    {
        $process = $this->createMock(Process::class);
        $process->method('getIncrementalOutput')->willReturnOnConsecutiveCalls('abcd', '');
        $process->method('getIncrementalErrorOutput')->willReturnOnConsecutiveCalls('ef', '');
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

        $message = $running->map(new ErrorMsg(new RuntimeException('pump failed'), ErrorSource::Process));

        self::assertInstanceOf(ErrorMsg::class, self::envelope($message)->inner);
    }

    #[Test]
    public function pumpStreamsIncrementally(): void
    {
        $process = $this->createMock(Process::class);

        $process->expects($this->exactly(3))
            ->method('getIncrementalOutput')
            ->willReturnOnConsecutiveCalls('chunk1', 'chunk2', '');

        $process->expects($this->exactly(3))
            ->method('getIncrementalErrorOutput')
            ->willReturn('');

        $process->expects($this->exactly(2))
            ->method('isRunning')
            ->willReturnOnConsecutiveCalls(true, false);

        $process->method('getExitCode')->willReturn(0);
        $process->method('isSuccessful')->willReturn(true);

        $running = new RunningStreamingProcess($process, EffectId::named('stream-test'));

        $firstPump = $running->pump();
        self::assertCount(1, $firstPump);
        self::assertInstanceOf(ProcessOutputMsg::class, $firstPump[0]);
        self::assertSame('chunk1', $firstPump[0]->output);

        $secondPump = $running->pump();
        self::assertCount(2, $secondPump);
        self::assertInstanceOf(ProcessOutputMsg::class, $secondPump[0]);
        self::assertSame('chunk2', $secondPump[0]->output);
        self::assertInstanceOf(ProcessFinishedMsg::class, $secondPump[1]);
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
        self::assertSame('fin', $messages[0]->effectId?->value);
        self::assertFalse($running->isRunning());
    }

    #[Test]
    public function aFinishedProcessAnnouncesItsEndOnlyOnce(): void
    {
        $process = $this->createMock(Process::class);
        $process->method('isRunning')->willReturn(false);
        $process->method('getIncrementalOutput')->willReturn('');
        $process->method('getIncrementalErrorOutput')->willReturn('');

        $running = new RunningStreamingProcess($process, null);
        $running->pump();

        self::assertSame([], $running->pump());
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

    #[Test]
    public function theTailFlushedAsTheChildExitsArrivesBeforeTheFinishedMessage(): void
    {
        $process = $this->createMock(Process::class);
        $process->method('getIncrementalOutput')->willReturnOnConsecutiveCalls('head', 'tail');
        $process->method('getIncrementalErrorOutput')->willReturnOnConsecutiveCalls('', 'err-tail');
        $process->method('isRunning')->willReturn(false);
        $process->method('getExitCode')->willReturn(0);
        $process->method('isSuccessful')->willReturn(true);

        $messages = (new RunningStreamingProcess($process, null))->pump();

        self::assertCount(4, $messages);
        self::assertInstanceOf(ProcessOutputMsg::class, $messages[0]);
        self::assertSame('head', $messages[0]->output);
        self::assertInstanceOf(ProcessOutputMsg::class, $messages[1]);
        self::assertSame('tail', $messages[1]->output);
        self::assertSame(ProcessStream::Stdout, $messages[1]->stream);
        self::assertInstanceOf(ProcessOutputMsg::class, $messages[2]);
        self::assertSame('err-tail', $messages[2]->output);
        self::assertSame(ProcessStream::Stderr, $messages[2]->stream);
        self::assertInstanceOf(ProcessFinishedMsg::class, $messages[3]);
    }

    #[Test]
    public function aFramedStreamDeliversOnlyWholeLinesAndTheUnterminatedTailWhenTheChildEnds(): void
    {
        $process = $this->createMock(Process::class);
        $process->method('getIncrementalOutput')->willReturnOnConsecutiveCalls("one\ntw", "o\nthr", '', '');
        $process->method('getIncrementalErrorOutput')->willReturn('');
        $process->method('isRunning')->willReturnOnConsecutiveCalls(true, true, false);
        $process->method('getExitCode')->willReturn(0);
        $process->method('isSuccessful')->willReturn(true);
        $running = new RunningStreamingProcess($process, null, framesLines: true);

        self::assertSame(["one\n"], self::outputsOf($running->pump()));
        self::assertSame(["two\n"], self::outputsOf($running->pump()));
        $last = $running->pump();
        self::assertSame(['thr'], self::outputsOf($last));
        self::assertInstanceOf(ProcessFinishedMsg::class, $last[1]);
    }

    #[Test]
    public function aCharacterSplitAcrossTwoReadsArrivesWhole(): void
    {
        $process = $this->createMock(Process::class);
        $process->method('getIncrementalOutput')->willReturnOnConsecutiveCalls("a\xC3", "\xA9b");
        $process->method('getIncrementalErrorOutput')->willReturn('');
        $process->method('isRunning')->willReturn(true);
        $running = new RunningStreamingProcess($process, null);

        self::assertSame(['a'], self::outputsOf($running->pump()));
        self::assertSame(['éb'], self::outputsOf($running->pump()));
    }

    #[Test]
    public function aCharacterSplitAcrossTwoReadsOfTheErrorStreamArrivesWhole(): void
    {
        $process = $this->createMock(Process::class);
        $process->method('getIncrementalOutput')->willReturn('');
        $process->method('getIncrementalErrorOutput')->willReturnOnConsecutiveCalls("a\xE2\x82", "\xACb");
        $process->method('isRunning')->willReturn(true);
        $running = new RunningStreamingProcess($process, null);

        $first = $running->pump();
        self::assertSame(['a'], self::outputsOf($first));
        self::assertInstanceOf(ProcessOutputMsg::class, $first[0]);
        self::assertSame(ProcessStream::Stderr, $first[0]->stream);
        self::assertSame(['€b'], self::outputsOf($running->pump()));
    }

    #[Test]
    public function aChunkNeverEndsInsideACharacter(): void
    {
        $process = $this->createMock(Process::class);
        $process->method('getIncrementalOutput')->willReturn('aéb');
        $process->method('getIncrementalErrorOutput')->willReturn('');
        $process->method('isRunning')->willReturn(true);

        self::assertSame(['a', 'é', 'b'], self::outputsOf((new RunningStreamingProcess($process, null, 2))->pump()));
    }

    #[Test]
    public function aFloodIsHandedOnOneBatchAtATimeSoAKeyNeverWaitsBehindAllOfIt(): void
    {
        $process = new Process([PHP_BINARY, '-r', 'for ($line = 0; $line < 300; ++$line) { echo str_pad((string)$line, 1023, "-"), "\n"; } sleep(5);']);
        $process->start();
        $running = new RunningStreamingProcess($process, null, 1024);
        usleep(300_000);

        $expected = implode('', array_map(static fn (int $line): string => str_pad((string)$line, 1023, '-') . "\n", range(0, 299)));
        $textOf = static fn (array $messages): string => implode('', array_map(
            static fn (Message $message): string => $message instanceof ProcessOutputMsg ? $message->output : '',
            $messages,
        ));
        $first = $running->pump();
        $received = $textOf($first);
        $deadline = microtime(true) + 2.0;
        while (\strlen($received) < \strlen($expected) && microtime(true) < $deadline) {
            $received .= $textOf($running->pump());
        }
        $running->stop();

        self::assertLessThanOrEqual(64, count($first));
        self::assertSame($expected, $received, 'what a batch held back still arrives, in order');
    }

    #[Test]
    public function aChildOutlivingItsTimeoutIsStoppedWithATimeoutError(): void
    {
        $process = new Process(['sleep', '5']);
        $process->setTimeout(0.2);
        $process->start();
        $running = new RunningStreamingProcess($process, null);

        $error = null;
        $deadline = microtime(true) + 2.0;
        while ($error === null && microtime(true) < $deadline) {
            usleep(20_000);
            try {
                $running->pump();
            } catch (ProcessTimedOutException $timedOut) {
                $error = $timedOut;
            }
        }
        $running->stop();

        self::assertInstanceOf(ProcessTimedOutException::class, $error);
    }

    #[Test]
    public function terminationEscalatesToKillAfterAGraceTooShortToStallTheLoop(): void
    {
        $process = $this->createMock(Process::class);
        $process->expects(self::once())->method('stop')->with(0.05);

        (new RunningStreamingProcess($process, null))->stop();
    }

    /**
     * @param list<Message> $messages
     *
     * @return list<string>
     */
    private static function outputsOf(array $messages): array
    {
        $outputs = [];
        foreach ($messages as $message) {
            if ($message instanceof ProcessOutputMsg) {
                $outputs[] = $message->output;
            }
        }

        return $outputs;
    }

    private static function envelope(Message $message): MessageEnvelope
    {
        self::assertInstanceOf(MessageEnvelope::class, $message);

        return $message;
    }
}
