<?php

declare(strict_types=1);

namespace Elmphant\Tui\Runtime\Trace;

use Elmphant\Tui\Runtime\Clock;
use Elmphant\Tui\Runtime\MonotonicClock;
use RuntimeException;

final class JsonlTracer implements Tracer
{
    private const BATCH = 1_000;
    private const BATCH_BYTES = 1_048_576;
    private const BATCH_SECONDS = 1.0;

    /**
     * @var list<string>
     */
    private array $pending = [];

    private int $pendingBytes = 0;

    private readonly float $startedAt;

    private float $writtenAt;

    private function __construct(
        private readonly string $path,
        private readonly Clock $clock,
        private readonly bool $live = false,
    ) {
        $this->startedAt = $this->writtenAt = $this->clock->now();
    }

    public static function toFile(string $path, Clock $clock = new MonotonicClock()): self
    {
        return new self($path, $clock);
    }

    /**
     * Write immediately for live tracing; this costs one write per event.
     */
    public static function live(string $path, Clock $clock = new MonotonicClock()): self
    {
        if (@file_put_contents($path, '', FILE_APPEND) === false) {
            throw new RuntimeException(sprintf('Unable to write the trace to %s', $path));
        }

        return new self($path, $clock, live: true);
    }

    /**
     * Throwing during shutdown would replace the failure being traced with a
     * failure to trace it.
     */
    public function __destruct()
    {
        try {
            $this->close();
        } catch (RuntimeException) {
        }
    }

    public function record(TraceEvent $event, array $fields): void
    {
        $now = $this->clock->now();
        $line = json_encode(
            [...$fields, 'event' => $event->value, 'at' => round($now - $this->startedAt, 6)],
            JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION,
        );
        $this->pending[] = $line;
        $this->pendingBytes += strlen($line);

        if ($this->live
            || count($this->pending) >= self::BATCH
            || $this->pendingBytes >= self::BATCH_BYTES
            || $now - $this->writtenAt >= self::BATCH_SECONDS) {
            $this->close();
        }
    }

    /**
     * Buffer events to avoid a write per event in the render loop.
     */
    public function close(): void
    {
        if ($this->pending === []) {
            return;
        }

        if (@file_put_contents($this->path, implode("\n", $this->pending) . "\n", FILE_APPEND) === false) {
            throw new RuntimeException(sprintf('Unable to write the trace to %s', $this->path));
        }

        $this->pending = [];
        $this->pendingBytes = 0;
        $this->writtenAt = $this->clock->now();
    }
}
