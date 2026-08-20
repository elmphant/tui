<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Input\Presets;

use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\Presets\VimMode;
use Elmphant\Tui\Message\KeyMsg;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class VimModeTest extends TestCase
{
    #[Test]
    public function bindsJAndKForListNav(): void
    {
        $bindings = VimMode::keybindings();

        self::assertTrue($bindings->matches(new KeyMsg('j'), Actions::CURSOR_DOWN));
        self::assertTrue($bindings->matches(new KeyMsg('k'), Actions::CURSOR_UP));
    }

    #[Test]
    public function bindsHAndLForTabNav(): void
    {
        $bindings = VimMode::keybindings();

        self::assertTrue($bindings->matches(new KeyMsg('l'), Actions::TAB_NEXT));
        self::assertTrue($bindings->matches(new KeyMsg('h'), Actions::TAB_PREV));
    }

    #[Test]
    public function bindsCtrlUAndCtrlDForPageNav(): void
    {
        $bindings = VimMode::keybindings();

        self::assertTrue($bindings->matches(new KeyMsg('u', ctrl: true), Actions::PAGE_UP));
        self::assertTrue($bindings->matches(new KeyMsg('d', ctrl: true), Actions::PAGE_DOWN));
    }

    #[Test]
    public function bindsJAndKForScroll(): void
    {
        $bindings = VimMode::keybindings();

        self::assertTrue($bindings->matches(new KeyMsg('j'), Actions::SCROLL_DOWN));
        self::assertTrue($bindings->matches(new KeyMsg('k'), Actions::SCROLL_UP));
    }

    #[Test]
    public function doesNotBindCursorLeftOrRight(): void
    {
        $bindings = VimMode::keybindings();

        self::assertSame([], $bindings->get(Actions::CURSOR_LEFT));
        self::assertSame([], $bindings->get(Actions::CURSOR_RIGHT));
    }

    #[Test]
    public function isCachedSingleton(): void
    {
        self::assertSame(VimMode::keybindings(), VimMode::keybindings());
    }
}
