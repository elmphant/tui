<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Testing\WidgetHarness;
use Elmphant\Tui\Widget\Structure\TabBar;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TabStripOverflowTest extends TestCase
{
    #[Test]
    public function aTabSwitchedToPastTheRightEdgeShowsInTheStrip(): void
    {
        $screen = WidgetHarness::for(self::fiveTabs(), width: 30, height: 8)
            ->press('right', 'right', 'right', 'right')
            ->screen();

        self::assertStringContainsString('Echo', $screen->lineAt(1));
    }

    #[Test]
    public function aMarkerStandsOnEachSideWhereTabsAreLeftOut(): void
    {
        $harness = WidgetHarness::for(self::fiveTabs(), width: 22, height: 8);

        self::assertSame('│ Alpha │  Bravo  ›', rtrim($harness->screen()->lineAt(1)));
        self::assertSame('‹  Bravo  │ Charlie │›', rtrim($harness->press('right', 'right')->screen()->lineAt(1)));
        self::assertSame('‹  Delta  │ Echo │', rtrim($harness->press('right', 'right')->screen()->lineAt(1)));
    }

    #[Test]
    public function aClickLandsOnTheTabDrawnUnderIt(): void
    {
        $harness = WidgetHarness::for(self::fiveTabs(), width: 30, height: 8)->press('right', 'right', 'right', 'right');

        self::assertSame(3, $harness->click('Delta')->widget()->activeTab());
        self::assertSame(2, $harness->click('Charlie')->widget()->activeTab());
    }

    #[Test]
    public function aSelectedTabWiderThanTheStripIsCutShortWithAnEllipsis(): void
    {
        $bar = TabBar::new()->addTab('One')->addTab('A label far wider than the strip')->addTab('Three');

        $strip = WidgetHarness::for($bar, width: 20, height: 8)->press('right')->screen()->lineAt(1);

        self::assertSame('‹│ A label far w… │›', $strip);
    }

    #[Test]
    public function tabsThatAllFitAreDrawnWithoutMarkers(): void
    {
        $screen = WidgetHarness::for(self::fiveTabs(), width: 46, height: 8)->screen();

        self::assertSame('│ Alpha │  Bravo    Charlie    Delta    Echo  ', $screen->lineAt(1));
    }

    private static function fiveTabs(): TabBar
    {
        $bar = TabBar::new();
        foreach (['Alpha', 'Bravo', 'Charlie', 'Delta', 'Echo'] as $label) {
            $bar = $bar->addTab($label);
        }

        return $bar;
    }
}
