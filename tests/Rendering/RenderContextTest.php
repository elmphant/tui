<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Rendering;

use Elmphant\Tui\Input\ColorProfile;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Theme;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RenderContextTest extends TestCase
{
    #[Test]
    public function aContextCarriesTheColourDepthItRendersFor(): void
    {
        $context = new RenderContext(Theme::dark(), ColorProfile::Ansi);

        self::assertSame(ColorProfile::Ansi, $context->colorProfile);
    }

    #[Test]
    public function aContextAssumesTrueColourUntilToldOtherwise(): void
    {
        $context = new RenderContext(Theme::dark());

        self::assertSame(ColorProfile::TrueColor, $context->colorProfile);
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
