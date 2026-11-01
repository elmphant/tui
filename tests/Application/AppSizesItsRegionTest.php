<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Tests\Application\Support\CopyableTestApp;
use Elmphant\Tui\Tests\Application\Support\TwoLineApp;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AppSizesItsRegionTest extends TestCase
{
    #[Test]
    public function anAppWantsAsManyRowsAsItsRootMeasures(): void
    {
        self::assertSame(2, (new TwoLineApp())->wantedHeight(40));
    }

    #[Test]
    public function anAppWhoseRootCannotMeasureWantsEveryRow(): void
    {
        self::assertSame(PHP_INT_MAX, (new UnmeasuredApp())->wantedHeight(40));
    }
}

final readonly class UnmeasuredApp extends CopyableTestApp
{
    public function view(): Widget
    {
        return new class implements Widget {
            public function sizing(): Sizing
            {
                return Sizing::of(Constraint::flex(), Constraint::flex());
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
            }
        };
    }
}
