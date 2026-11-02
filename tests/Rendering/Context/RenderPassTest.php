<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Rendering\Context;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Context\RenderPass;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Theme;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RenderPassTest extends TestCase
{
    #[Test]
    public function theRootDrawsInTheWholeFrameUnlessGivenLess(): void
    {
        $frame = new Frame(20, 10);
        $pass = RenderPass::new(Theme::dark());

        self::assertEquals(Rect::fromSize(20, 10), $pass->areaIn($frame));
        self::assertEquals(Rect::fromSize(20, 3), $pass->withArea(Rect::fromSize(20, 3))->areaIn($frame));
    }

    #[Test]
    public function aThemeChangeKeepsTheArea(): void
    {
        $pass = RenderPass::new(Theme::dark())->withArea(Rect::fromSize(20, 3))->withTheme(Theme::light());

        self::assertEquals(Rect::fromSize(20, 3), $pass->areaIn(new Frame(20, 10)));
    }
}
