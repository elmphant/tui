<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Rendering;

use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Theme;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RenderContextTest extends TestCase
{
    #[Test]
    public function scratchBufferReusedAcrossCalls(): void
    {
        $context = new RenderContext(Theme::dark());
        $buffer1 = $context->scratchBuffer(20, 1);
        $buffer2 = $context->scratchBuffer(20, 1);
        $this->assertSame($buffer1, $buffer2);
    }

    #[Test]
    public function scratchBufferReallocatesWhenWidthGrows(): void
    {
        // L17 LogicalOr: `width < W || height < H`. When only width grew, must reallocate.
        $context = new RenderContext(Theme::dark());
        $small = $context->scratchBuffer(10, 5);
        $bigger = $context->scratchBuffer(20, 5);

        self::assertNotSame($small, $bigger, 'Wider scratch must reallocate');
        self::assertSame(20, $bigger->width);
        self::assertSame(5, $bigger->height);
    }

    #[Test]
    public function scratchBufferReallocatesWhenHeightGrows(): void
    {
        // L17 LogicalOr second clause: when only height grew, must reallocate.
        $context = new RenderContext(Theme::dark());
        $small = $context->scratchBuffer(10, 5);
        $bigger = $context->scratchBuffer(10, 10);

        self::assertNotSame($small, $bigger, 'Taller scratch must reallocate');
        self::assertSame(10, $bigger->width);
        self::assertSame(10, $bigger->height);
    }

    #[Test]
    public function scratchBufferGrowsToMaxOfExistingAndRequested(): void
    {
        // L19/L20 max(W, this->scratch?->width ?? 0): when reallocating, must preserve existing dimensions if they were larger.
        // First request: 20x5. Then request 10x10. Result must be 20x10 (max of each).
        $context = new RenderContext(Theme::dark());
        $context->scratchBuffer(20, 5);
        $combined = $context->scratchBuffer(10, 10);

        self::assertSame(20, $combined->width, 'Width must grow to max — existing 20 must be preserved');
        self::assertSame(10, $combined->height, 'Height must grow to max');
    }

    #[Test]
    public function scratchBufferHeightPreservedWhenOnlyWidthGrows(): void
    {
        // L20 Coalesce: `$this->scratch?->height ?? 0` — kills the mutation that swaps to `0 ?? scratch?->height` (always 0).
        // First request: 5x30 (tall). Second request: 10x5 (wider, shorter). Height must stay 30.
        $context = new RenderContext(Theme::dark());
        $context->scratchBuffer(5, 30);
        $bigger = $context->scratchBuffer(10, 5);

        self::assertSame(10, $bigger->width, 'Width must grow to 10');
        self::assertSame(30, $bigger->height, 'Existing height 30 must be preserved (kills L20 Coalesce mutant)');
    }

    #[Test]
    public function scratchBufferWidthPreservedWhenOnlyHeightGrows(): void
    {
        // L19 Coalesce: same for width.
        $context = new RenderContext(Theme::dark());
        $context->scratchBuffer(30, 5);
        $bigger = $context->scratchBuffer(5, 10);

        self::assertSame(30, $bigger->width, 'Existing width 30 must be preserved (kills L19 Coalesce mutant)');
        self::assertSame(10, $bigger->height, 'Height must grow to 10');
    }

    #[Test]
    public function scratchBufferFirstAllocationUsesRequestedDimensions(): void
    {
        // L19/L20 Coalesce: `$this->scratch?->width ?? 0` — when scratch is null, default to 0.
        // The max(width, 0) must yield width (not the alternative defaults -1/1).
        $context = new RenderContext(Theme::dark());
        $frame = $context->scratchBuffer(7, 3);

        self::assertSame(7, $frame->width, 'First allocation width must be exactly the requested width');
        self::assertSame(3, $frame->height, 'First allocation height must be exactly the requested height');
    }

    #[Test]
    public function scratchBufferIsClearedWhenReused(): void
    {
        // L23 MethodCallRemoval: the `$this->scratch->clear()` call must run on reuse so stale data doesn't leak.
        $context = new RenderContext(Theme::dark());
        $buffer1 = $context->scratchBuffer(5, 1);
        $buffer1->putChar(0, 0, 'X');
        $buffer1->putChar(1, 0, 'Y');

        // Reuse the scratch (same size — no realloc).
        $buffer2 = $context->scratchBuffer(5, 1);
        self::assertSame($buffer1, $buffer2, 'Buffer is reused');
        // After reuse, cells must be cleared.
        self::assertSame(' ', $buffer2->characters[0], 'Cell 0 must be cleared on reuse');
        self::assertSame(' ', $buffer2->characters[1], 'Cell 1 must be cleared on reuse');
    }

    #[Test]
    public function scratchBufferAtExactSizeDoesNotReallocate(): void
    {
        // L17 `<` boundary: when requested width equals existing width and height equals existing height, no realloc.
        $context = new RenderContext(Theme::dark());
        $buffer1 = $context->scratchBuffer(10, 5);
        $buffer2 = $context->scratchBuffer(10, 5);

        self::assertSame($buffer1, $buffer2, 'Exact-size request must reuse the existing buffer');
    }

    #[Test]
    public function scratchBufferSmallerRequestReusesBuffer(): void
    {
        // L17 `<`: when requested dimensions are smaller, the existing buffer is reused (no realloc).
        $context = new RenderContext(Theme::dark());
        $buffer1 = $context->scratchBuffer(20, 10);
        $buffer2 = $context->scratchBuffer(5, 3);

        self::assertSame($buffer1, $buffer2, 'Smaller request must reuse the existing (larger) buffer');
    }

    #[Test]
    public function frameTimeDefaultsToZero(): void
    {
        $context = new RenderContext(Theme::dark());

        self::assertSame(0.0, $context->now());
    }

    #[Test]
    public function beginFrameSetsTheFrameTime(): void
    {
        $context = new RenderContext(Theme::dark());

        $context->beginFrame(2.5);

        self::assertSame(2.5, $context->now());
    }

    #[Test]
    public function repaintDelayIsNullWithoutRequests(): void
    {
        $context = new RenderContext(Theme::dark());

        self::assertNull($context->repaintDelay());
    }

    #[Test]
    public function requestRepaintRecordsTheDelay(): void
    {
        $context = new RenderContext(Theme::dark());

        $context->requestRepaint(0.08);

        self::assertSame(0.08, $context->repaintDelay());
    }

    #[Test]
    public function requestRepaintKeepsTheSmallestDelay(): void
    {
        $context = new RenderContext(Theme::dark());

        $context->requestRepaint(0.08);
        $context->requestRepaint(0.05);
        $context->requestRepaint(0.2);

        self::assertSame(0.05, $context->repaintDelay());
    }

    #[Test]
    public function requestRepaintClampsNegativeDelaysToZero(): void
    {
        $context = new RenderContext(Theme::dark());

        $context->requestRepaint(-1.0);

        self::assertSame(0.0, $context->repaintDelay());
    }

    #[Test]
    public function beginFrameClearsPendingRepaintRequests(): void
    {
        $context = new RenderContext(Theme::dark());
        $context->requestRepaint(0.08);

        $context->beginFrame(1.0);

        self::assertNull($context->repaintDelay());
    }
}
