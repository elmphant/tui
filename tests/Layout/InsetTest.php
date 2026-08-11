<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Layout;

use Elmphant\Tui\Layout\Inset;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Spacing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class InsetTest extends TestCase
{
    #[Test]
    public function takesTheMarginThenTheBorderThenThePadding(): void
    {
        $inset = new Inset(margin: Spacing::all(1), border: true, padding: Spacing::all(2));

        self::assertEquals(new Rect(4, 4, 32, 4), $inset->inner(new Rect(0, 0, 40, 12)));
    }
}
