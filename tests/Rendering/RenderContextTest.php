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
}
