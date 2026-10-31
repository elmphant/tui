<?php

declare(strict_types=1);

namespace Elmphant\Tui\Runtime\Trace;

use Elmphant\Tui\Runtime\Clock;
use Elmphant\Tui\Runtime\MonotonicClock;
use RuntimeException;

final class JsonlTracer implements Tracer
{
    private const BATCH = 1_000;

    /**
     * @var list<string>
     */
    private array $pending = [];

    private readonly float $startedAt;

    private function __construct(
        private readonly string $path,
        private readonly Clock $clock,
        private readonly bool $live = false,
    ) {
        $this->startedAt = $this->clock->now();
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
        $this->pending[] = json_encode(
            [...$fields, 'event' => $event->value, 'at' => round($this->clock->now() - $this->startedAt, 6)],
            JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION,
        );

        if ($this->live || count($this->pending) >= self::BATCH) {
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
    }
}
