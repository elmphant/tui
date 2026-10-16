<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime\Trace;

use Elmphant\Tui\Runtime\Trace\JsonlTracer;
use Elmphant\Tui\Runtime\Trace\TraceEvent;
use Elmphant\Tui\Testing\TestClock;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class JsonlTracerTest extends TestCase
{
    private string $path = '';

    #[Test]
    public function writesOneJsonObjectPerEvent(): void
    {
        $tracer = JsonlTracer::toFile($this->path);

        $tracer->record(TraceEvent::Update, ['message' => 'KeyMsg', 'ms' => 0.5]);
        $tracer->record(TraceEvent::Render, ['bytes' => 42]);
        $tracer->close();

        $lines = $this->lines();

        self::assertCount(2, $lines);
        self::assertSame('update', $lines[0]['event']);
        self::assertSame('KeyMsg', $lines[0]['message']);
        self::assertSame('render', $lines[1]['event']);
        self::assertSame(42, $lines[1]['bytes']);
    }

    #[Test]
    public function aLiveTraceIsOnDiskAsEachEventHappens(): void
    {
        $tracer = JsonlTracer::live($this->path);

        $tracer->record(TraceEvent::Update, ['message' => 'KeyMsg']);

        self::assertCount(1, $this->lines(), 'readable by tail -f before the program stops');

        $tracer->record(TraceEvent::Render, ['bytes' => 42]);
        $tracer->close();

        self::assertCount(2, $this->lines());
    }

    #[Test]
    public function stampsEachEventWithTheSecondsSinceTracingBegan(): void
    {
        $clock = new TestClock(1_000.0);
        $tracer = JsonlTracer::toFile($this->path, $clock);

        $tracer->record(TraceEvent::Update, []);
        $clock->advance(0.25);
        $tracer->record(TraceEvent::Update, []);
        $tracer->close();

        $lines = $this->lines();

        self::assertSame(0.0, $lines[0]['at'], 'The first event sits at the origin, not at the clock epoch');
        self::assertSame(0.25, $lines[1]['at']);
    }

    #[Test]
    public function holdsEventsUntilClosedSoTracingDoesNotBecomeItsOwnBottleneck(): void
    {
        $tracer = JsonlTracer::toFile($this->path);

        $tracer->record(TraceEvent::Render, ['bytes' => 1]);

        self::assertFileDoesNotExist($this->path, 'A render loop cannot afford a write per event');

        $tracer->close();

        self::assertFileExists($this->path);
    }

    #[Test]
    public function aTraceStillLandsWhenNobodyClosedIt(): void
    {
        $tracer = JsonlTracer::toFile($this->path);
        $tracer->record(TraceEvent::Render, ['bytes' => 7]);

        unset($tracer);

        self::assertFileExists($this->path, 'A program that quits or throws must not lose its trace');
        self::assertSame(7, $this->lines()[0]['bytes']);
    }

    #[Test]
    public function closingWithoutHavingRecordedAnythingLeavesNoFileBehind(): void
    {
        JsonlTracer::toFile($this->path)->close();

        self::assertFileDoesNotExist($this->path, 'An untraced run must not litter an empty trace');
    }

    #[Test]
    public function aSecondFlushAppendsWholeLinesRatherThanJoiningOntoTheLast(): void
    {
        $tracer = JsonlTracer::toFile($this->path);
        $tracer->record(TraceEvent::Update, ['n' => 1]);
        $tracer->close();
        $tracer->record(TraceEvent::Update, ['n' => 2]);
        $tracer->close();

        $lines = $this->lines();

        self::assertCount(2, $lines);
        self::assertSame(1, $lines[0]['n']);
        self::assertSame(2, $lines[1]['n']);
        self::assertStringEndsWith("\n", (string)file_get_contents($this->path), 'The newline terminates a line, it does not begin one');
    }

    #[Test]
    public function aTraceThatCannotBeWrittenSaysSoRatherThanVanishing(): void
    {
        $tracer = JsonlTracer::toFile($this->path . '/no/such/directory/trace.jsonl');
        $tracer->record(TraceEvent::Update, []);

        $this->expectException(RuntimeException::class);
        $tracer->close();
    }

    #[Test]
    public function timestampsKeepMicrosecondResolution(): void
    {
        $clock = new TestClock(0.0);
        $tracer = JsonlTracer::toFile($this->path, $clock);

        $clock->advance(0.0000006);
        $tracer->record(TraceEvent::Update, []);
        $tracer->close();

        self::assertSame(0.000001, $this->lines()[0]['at']);
    }

    #[Test]
    public function aFieldNameCollidingWithTheEnvelopeDoesNotHideTheEventName(): void
    {
        $tracer = JsonlTracer::toFile($this->path);

        $tracer->record(TraceEvent::Command, ['event' => 'not the event', 'at' => 'not the time']);
        $tracer->close();

        self::assertSame('command', $this->lines()[0]['event']);
    }

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'trace');
        self::assertIsString($path);
        $this->path = $path;
        unlink($this->path);
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lines(): array
    {
        $contents = file_get_contents($this->path);
        self::assertIsString($contents);

        $lines = [];
        foreach (explode("\n", trim($contents)) as $line) {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            $lines[] = $decoded;
        }

        return $lines;
    }
}
