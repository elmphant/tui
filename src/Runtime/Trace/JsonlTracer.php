<?php

declare(strict_types=1);

namespace Elmphant\Tui\Runtime\Trace;

use Elmphant\Tui\Runtime\Clock;
use Elmphant\Tui\Runtime\MonotonicClock;
use RuntimeException;

/**
 * Writes the trace as one JSON object per line, for grepping and totalling.
 */
final class JsonlTracer implements Tracer
{
    /**
     * @var list<string>
     */
    private array $pending = [];

    private readonly float $startedAt;

    private function __construct(
        private readonly string $path,
        private readonly Clock $clock,
    ) {
        $this->startedAt = $this->clock->now();
    }

    public static function toFile(string $path, ?Clock $clock = null): self
    {
        return new self($path, $clock ?? new MonotonicClock());
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
    }

    /**
     * A render loop cannot afford a write per event, so the trace is held until
     * the program stops.
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
