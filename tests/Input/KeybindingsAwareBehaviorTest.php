<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Input;

use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Input\KeybindingsAware;
use Elmphant\Tui\Input\KeybindingsAwareBehavior;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Support\Cloneable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final readonly class FakeKeybindingsWidget implements KeybindingsAware
{
    use Cloneable;
    use KeybindingsAwareBehavior;

    public function __construct(
        private ?Keybindings $customKeybindings = null,
        private ?Keybindings $globalKeybindings = null,
    ) {
    }

    public static function defaultKeybindings(): Keybindings
    {
        return new Keybindings([
            Actions::CURSOR_DOWN => ['down', 'j'],
            Actions::SELECT => ['enter'],
        ]);
    }
}

final class KeybindingsAwareBehaviorTest extends TestCase
{
    #[Test]
    public function effectiveKeybindingsReturnsDefaultsWhenNoCustom(): void
    {
        $widget = new FakeKeybindingsWidget();

        $bindings = $widget->effectiveKeybindings();

        self::assertTrue($bindings->matches(new KeyMsg('j'), Actions::CURSOR_DOWN));
        self::assertTrue($bindings->matches(new KeyMsg(Key::Down), Actions::CURSOR_DOWN));
        self::assertFalse($bindings->matches(new KeyMsg('x'), Actions::CURSOR_DOWN));
    }

    #[Test]
    public function withKeybindingsReplacesDefaultsPerAction(): void
    {
        $widget = new FakeKeybindingsWidget();

        $customized = $widget->withKeybindings(new Keybindings([
            Actions::CURSOR_DOWN => ['x'],
        ]));

        $bindings = $customized->effectiveKeybindings();

        self::assertTrue($bindings->matches(new KeyMsg('x'), Actions::CURSOR_DOWN));
        self::assertFalse($bindings->matches(new KeyMsg('j'), Actions::CURSOR_DOWN));
        self::assertTrue($bindings->matches(new KeyMsg(Key::Enter), Actions::SELECT));
    }

    #[Test]
    public function withKeybindingsNullRestoresDefaults(): void
    {
        $customized = (new FakeKeybindingsWidget())
            ->withKeybindings(new Keybindings([Actions::CURSOR_DOWN => ['x']]));

        $restored = $customized->withKeybindings(null);

        $bindings = $restored->effectiveKeybindings();
        self::assertTrue($bindings->matches(new KeyMsg('j'), Actions::CURSOR_DOWN));
        self::assertFalse($bindings->matches(new KeyMsg('x'), Actions::CURSOR_DOWN));
    }

    #[Test]
    public function globalKeybindingsAppendsToDefaults(): void
    {
        $widget = new FakeKeybindingsWidget();
        $global = new Keybindings([Actions::CURSOR_DOWN => ['x']]);

        $wrapped = $widget->withGlobalKeybindings($global);
        $bindings = $wrapped->effectiveKeybindings();

        self::assertTrue($bindings->matches(new KeyMsg('j'), Actions::CURSOR_DOWN));
        self::assertTrue($bindings->matches(new KeyMsg('x'), Actions::CURSOR_DOWN));
    }

    #[Test]
    public function globalKeybindingsDoesNotReplaceDefaults(): void
    {
        $widget = new FakeKeybindingsWidget();
        $global = new Keybindings([Actions::CURSOR_DOWN => ['tab']]);

        $wrapped = $widget->withGlobalKeybindings($global);
        $bindings = $wrapped->effectiveKeybindings();

        self::assertTrue($bindings->matches(new KeyMsg('j'), Actions::CURSOR_DOWN));
        self::assertTrue($bindings->matches(new KeyMsg(Key::Down), Actions::CURSOR_DOWN));
    }

    #[Test]
    public function anInstanceOverrideReplacesTheDefaultsAndKeepsTheGlobals(): void
    {
        $widget = new FakeKeybindingsWidget();
        $global = new Keybindings([Actions::CURSOR_DOWN => ['tab']]);
        $instance = new Keybindings([Actions::CURSOR_DOWN => ['z']]);

        $wrapped = $widget
            ->withGlobalKeybindings($global)
            ->withKeybindings($instance);

        $bindings = $wrapped->effectiveKeybindings();

        self::assertTrue($bindings->matches(new KeyMsg('z'), Actions::CURSOR_DOWN));
        self::assertFalse($bindings->matches(new KeyMsg('j'), Actions::CURSOR_DOWN));
        self::assertTrue($bindings->matches(new KeyMsg(Key::Tab), Actions::CURSOR_DOWN));
    }

    #[Test]
    public function withGlobalKeybindingsIdentitySentinel(): void
    {
        $widget = new FakeKeybindingsWidget();
        $global = new Keybindings([Actions::CURSOR_DOWN => ['x']]);

        $wrapped = $widget->withGlobalKeybindings($global);
        $wrappedAgain = $wrapped->withGlobalKeybindings($global);

        self::assertSame($wrapped, $wrappedAgain);
    }

    #[Test]
    public function withGlobalKeybindingsNullInitialIsCloned(): void
    {
        $widget = new FakeKeybindingsWidget();

        $wrapped = $widget->withGlobalKeybindings(Keybindings::empty());

        self::assertNotSame($widget, $wrapped);
        self::assertSame($wrapped, $wrapped->withGlobalKeybindings(Keybindings::empty()));
    }
}
