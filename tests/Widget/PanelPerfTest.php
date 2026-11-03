<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Tests\Support\PerformanceBudget;
use Elmphant\Tui\Widget\Plan\LayoutPlan;
use Elmphant\Tui\Widget\Prose\Paragraph;
use Elmphant\Tui\Widget\Structure\Panel;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PanelPerfTest extends TestCase
{
    use PerformanceBudget;

    #[Test]
    public function aPanelsPlanGrowsInStepWithItsChildren(): void
    {
        self::skipUnderXdebug();

        $area = Rect::fromSize(100, 40);
        $few = self::panelOf(200);
        $many = self::panelOf(1_600);

        self::assertRatioBelow(16.0, static function () use ($few, $many, $area): array {
            $short = self::medianMicroseconds(static fn (): LayoutPlan => LayoutPlan::resolve($few, $area), 5);
            $long = self::medianMicroseconds(static fn (): LayoutPlan => LayoutPlan::resolve($many, $area), 5);
            $ratio = $long / $short;

            return [$ratio, sprintf('8x the children made the plan %.1fx slower (%.0fµs vs %.0fµs).', $ratio, $long, $short)];
        });
    }

    private static function panelOf(int $count): Panel
    {
        $children = [];
        for ($index = 1; $index <= $count; ++$index) {
            $children[] = Paragraph::fromText("message {$index} with text");
        }

        return Panel::new('log')->withChildren($children);
    }
}
