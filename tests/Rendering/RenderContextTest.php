<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Rendering;

use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\FocusState;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\RenderPass;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Terminal\ColorProfile;
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
    public function aWidgetCanAskWhichComponentHoldsTheKeyboard(): void
    {
        $pass = RenderPass::new(Theme::dark());
        $pass->focus->focusOn(new FocusState(ComponentId::named('filter/0')));

        self::assertEquals(ComponentId::named('filter/0'), $pass->context->focusedComponent());
    }

    #[Test]
    public function aContextAssumesTrueColourUntilToldOtherwise(): void
    {
        $context = new RenderContext(Theme::dark());

        self::assertSame(ColorProfile::TrueColor, $context->colorProfile);
    }
}
