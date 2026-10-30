<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Structure\Panel;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Preserve pane scroll and focus state when the host rebuilds child rows from data.
 */
final class PanelKeepsItsPaneStateTest extends TestCase
{
    #[Test]
    public function newChildrenKeepWhereThePaneWasScrolled(): void
    {
        $panel = self::panelOfLines(10)->withScrollOffset(3);

        $rebuilt = $panel->withChildren([...self::panelOfLines(10)->children()]);

        self::assertSame(3, $rebuilt->scrollOffset(Rect::fromSize(20, 6)));
    }

    private static function panelOfLines(int $count): Panel
    {
        $panel = Panel::new('Lines');

        foreach (range(1, $count) as $line) {
            $panel = $panel->add(Text::new('line' . $line));
        }

        return $panel;
    }
}
