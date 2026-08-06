<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Layout;

use Elmphant\Tui\Layout\VerticalStackLayout;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class VerticalStackLayoutTest extends TestCase
{
    #[Test]
    public function contentThatExactlyFillsTheViewportHoldsNoScrollOffset(): void
    {
        $layout = new VerticalStackLayout([10], 10, 20, false);

        self::assertSame(0, $layout->maxScrollOffset(10));
        self::assertSame(0, $layout->clampScrollOffset(1, 10), 'Nothing overflows, so no row may be scrolled out of view');
    }
}
