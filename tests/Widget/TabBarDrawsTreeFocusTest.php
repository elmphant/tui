<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Widget\Structure\TabBar;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TabBarDrawsTreeFocusTest extends TestCase
{
    #[Test]
    public function aBarTheTreeFocusesAdvertisesTheKeysItAnswers(): void
    {
        $bar = TabBar::new()->addTab('One')->addTab('Two');
        $area = new Rect(0, 0, 60, 8);

        $focused = new Frame(60, 8);
        $bar->render($focused, $area, RenderContext::focused(Theme::dark()));

        $plain = new Frame(60, 8);
        $bar->render($plain, $area, RenderContext::unfocused(Theme::dark()));

        self::assertStringContainsString('← → to switch', implode('', $focused->characters), 'the bar names its keys where the keyboard is');
        self::assertStringNotContainsString('← → to switch', implode('', $plain->characters), 'and stays quiet where it is not');
    }
}
