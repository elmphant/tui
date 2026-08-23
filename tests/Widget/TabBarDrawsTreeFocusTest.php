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
    public function aBarTheTreeFocusesAdvertisesTheSameKeysAsOneFocusedByItsOwnFlag(): void
    {
        $bar = TabBar::new()->addTab('One')->addTab('Two');
        $area = new Rect(0, 0, 60, 8);

        $byFlag = new Frame(60, 8);
        $bar->withFocus(true)->render($byFlag, $area, new RenderContext(Theme::dark()));

        $byTree = new Frame(60, 8);
        $context = new RenderContext(Theme::dark());
        $context->withFocusHere(static function () use ($bar, $byTree, $area, $context): void {
            $bar->render($byTree, $area, $context);
        });

        self::assertSame(implode('', $byFlag->characters), implode('', $byTree->characters));
    }
}
