<?php

declare(strict_types=1);

namespace Elmphant\Tui\Testing;

use Elmphant\Tui\Runtime\Trace\TraceEvent;
use Elmphant\Tui\Runtime\Trace\Tracer;

/**
 * Holds the trace so a test can assert on the flow it expected.
 */
final class InMemoryTracer implements Tracer
{
    /**
     * @var list<array{TraceEvent, array<string, scalar|null>}>
     */
    private array $records = [];

    public function record(TraceEvent $event, array $fields): void
    {
        $this->records[] = [$event, $fields];
    }

    /**
     * @return list<array{TraceEvent, array<string, scalar|null>}>
     */
    public function records(): array
    {
        return $this->records;
    }

    /**
     * @return list<array<string, scalar|null>>
     */
    public function fieldsOf(TraceEvent $event): array
    {
        $fields = [];
        foreach ($this->records as [$recorded, $recordedFields]) {
            if ($recorded === $event) {
                $fields[] = $recordedFields;
            }
        }

        return $fields;
    }

    public function countOf(TraceEvent $event): int
    {
        return count($this->fieldsOf($event));
    }

    /**
     * Totals one numeric field across every event of a kind, so a trace can be
     * read as "how much time went here".
     */
    public function totalOf(TraceEvent $event, string $field): float
    {
        $total = 0.0;
        foreach ($this->fieldsOf($event) as $fields) {
            $value = $fields[$field] ?? null;
            if (is_int($value) || is_float($value)) {
                $total += $value;
            }
        }

        return $total;
    }
}
