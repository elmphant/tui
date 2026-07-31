<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Input;

use Elmphant\Tui\Core\Cloneable;
use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Input\KeybindingsAware;
use Elmphant\Tui\Input\KeybindingsAwareBehavior;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
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
            Actions::FOCUS_NEXT => ['down', 'j'],
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

        self::assertTrue($bindings->matches(new KeyMsg('j'), Actions::FOCUS_NEXT));
        self::assertTrue($bindings->matches(new KeyMsg(Key::Down), Actions::FOCUS_NEXT));
        self::assertFalse($bindings->matches(new KeyMsg('x'), Actions::FOCUS_NEXT));
    }

    #[Test]
    public function withKeybindingsReplacesDefaultsPerAction(): void
    {
        $widget = new FakeKeybindingsWidget();

        $customized = $widget->withKeybindings(new Keybindings([
            Actions::FOCUS_NEXT => ['x'],
        ]));

        $bindings = $customized->effectiveKeybindings();

        self::assertTrue($bindings->matches(new KeyMsg('x'), Actions::FOCUS_NEXT));
        self::assertFalse($bindings->matches(new KeyMsg('j'), Actions::FOCUS_NEXT));
        self::assertTrue($bindings->matches(new KeyMsg(Key::Enter), Actions::SELECT));
    }

    #[Test]
    public function withKeybindingsNullRestoresDefaults(): void
    {
        $customized = (new FakeKeybindingsWidget())
            ->withKeybindings(new Keybindings([Actions::FOCUS_NEXT => ['x']]));

        $restored = $customized->withKeybindings(null);

        $bindings = $restored->effectiveKeybindings();
        self::assertTrue($bindings->matches(new KeyMsg('j'), Actions::FOCUS_NEXT));
        self::assertFalse($bindings->matches(new KeyMsg('x'), Actions::FOCUS_NEXT));
    }

    #[Test]
    public function globalKeybindingsAppendsToDefaults(): void
    {
        $widget = new FakeKeybindingsWidget();
        $global = new Keybindings([Actions::FOCUS_NEXT => ['x']]);

        $wrapped = $widget->withGlobalKeybindings($global);
        $bindings = $wrapped->effectiveKeybindings();

        self::assertTrue($bindings->matches(new KeyMsg('j'), Actions::FOCUS_NEXT));
        self::assertTrue($bindings->matches(new KeyMsg('x'), Actions::FOCUS_NEXT));
    }

    #[Test]
    public function globalKeybindingsDoesNotReplaceDefaults(): void
    {
        $widget = new FakeKeybindingsWidget();
        $global = new Keybindings([Actions::FOCUS_NEXT => ['tab']]);

        $wrapped = $widget->withGlobalKeybindings($global);
        $bindings = $wrapped->effectiveKeybindings();

        self::assertTrue($bindings->matches(new KeyMsg('j'), Actions::FOCUS_NEXT));
        self::assertTrue($bindings->matches(new KeyMsg(Key::Down), Actions::FOCUS_NEXT));
    }

    #[Test]
    public function instanceOverrideStillReplacesPerAction(): void
    {
        $widget = new FakeKeybindingsWidget();
        $global = new Keybindings([Actions::FOCUS_NEXT => ['tab']]);
        $instance = new Keybindings([Actions::FOCUS_NEXT => ['z']]);

        $wrapped = $widget
            ->withGlobalKeybindings($global)
            ->withKeybindings($instance);

        $bindings = $wrapped->effectiveKeybindings();

        self::assertTrue($bindings->matches(new KeyMsg('z'), Actions::FOCUS_NEXT));
        self::assertFalse($bindings->matches(new KeyMsg('j'), Actions::FOCUS_NEXT));
        self::assertFalse($bindings->matches(new KeyMsg(Key::Tab), Actions::FOCUS_NEXT));
    }

    #[Test]
    public function withGlobalKeybindingsIdentitySentinel(): void
    {
        $widget = new FakeKeybindingsWidget();
        $global = new Keybindings([Actions::FOCUS_NEXT => ['x']]);

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
