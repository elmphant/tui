<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Widget\TabBar;
use Elmphant\Tui\Widget\TabPage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The reader is looking at a page, not at a place on the strip. A host that adds a
 * tab in front of the one they opened left the number where it was, so the bar
 * silently showed them a different page.
 */
final class ATabBarKeepsTheTabItWasShowingTest extends TestCase
{
    #[Test]
    public function aTabAddedInFrontDoesNotSwitchThePageBeingRead(): void
    {
        $bar = TabBar::new()->addTab('Alpha')->addTab('Beta')->addTab('Gamma')->withActiveTab(2);
        self::assertSame(2, $bar->activeTab(), 'precondition: the reader opened Gamma');

        $grown = $bar->withTabs([
            new TabPage('Zulu'),
            new TabPage('Alpha'),
            new TabPage('Beta'),
            new TabPage('Gamma'),
        ]);

        self::assertSame(3, $grown->activeTab(), 'the page they were reading is the page still shown');
    }
}
