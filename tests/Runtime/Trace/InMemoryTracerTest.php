<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime\Trace;

use Elmphant\Tui\Runtime\Trace\TraceEvent;
use Elmphant\Tui\Testing\InMemoryTracer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class InMemoryTracerTest extends TestCase
{
    #[Test]
    public function keepsEveryRecordedEventInOrder(): void
    {
        $tracer = new InMemoryTracer();

        $tracer->record(TraceEvent::Update, ['message' => 'KeyMsg']);
        $tracer->record(TraceEvent::Render, ['bytes' => 120]);

        self::assertSame(
            [
                [TraceEvent::Update, ['message' => 'KeyMsg']],
                [TraceEvent::Render, ['bytes' => 120]],
            ],
            $tracer->records(),
        );
    }

    #[Test]
    public function reportsTheFieldsOfEveryEventOfOneKind(): void
    {
        $tracer = new InMemoryTracer();

        $tracer->record(TraceEvent::Update, ['ms' => 1]);
        $tracer->record(TraceEvent::Render, ['ms' => 9]);
        $tracer->record(TraceEvent::Update, ['ms' => 2]);

        self::assertSame([['ms' => 1], ['ms' => 2]], $tracer->fieldsOf(TraceEvent::Update));
    }

    #[Test]
    public function countsHowOftenAnEventHappened(): void
    {
        $tracer = new InMemoryTracer();

        $tracer->record(TraceEvent::FrameSkipped, []);
        $tracer->record(TraceEvent::FrameSkipped, []);

        self::assertSame(2, $tracer->countOf(TraceEvent::FrameSkipped));
        self::assertSame(0, $tracer->countOf(TraceEvent::Render));
    }

    #[Test]
    public function sumsANumericFieldSoATraceCanBeTotalled(): void
    {
        $tracer = new InMemoryTracer();

        $tracer->record(TraceEvent::Render, ['blockedMs' => 4.5]);
        $tracer->record(TraceEvent::Render, ['blockedMs' => 1.5]);
        $tracer->record(TraceEvent::Render, ['bytes' => 10]);

        self::assertSame(6.0, $tracer->totalOf(TraceEvent::Render, 'blockedMs'));
    }
}
