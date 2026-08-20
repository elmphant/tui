<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Widget\Panel;
use Elmphant\Tui\Widget\TextInput;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A host that rebuilds its view hands the panel the same children every frame. The
 * guard against rebuilding compared them against what the pane reports, which is
 * its children with focus applied — so a focused panel never matched itself, built
 * a fresh pane every time, and dropped the scroll position and the inner cursor
 * with it.
 */
final class HandingAPanelTheChildrenItHasChangesNothingTest extends TestCase
{
    #[Test]
    public function aFocusedPanelKeepsItsPaneWhenHandedTheSameChildren(): void
    {
        $children = [new TextInput(), new TextInput()];
        $panel = Panel::new('Log')->withChildren($children)->withFocus(true);

        self::assertSame($panel, $panel->withChildren($children));
    }
}
