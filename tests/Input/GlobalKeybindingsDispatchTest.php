<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Input;

use Elmphant\Tui\Core\Cloneable;
use Elmphant\Tui\Core\Component;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Input\KeybindingsAware;
use Elmphant\Tui\Input\KeybindingsAwareBehavior;
use Elmphant\Tui\Input\Presets\VimMode;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseButton;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Message\TickMsg;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Tests\Core\Support\CopyableTestApp;
use Elmphant\Tui\Widget\Choice\Select;
use Elmphant\Tui\Widget\Focusable;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Structure\Panel;
use Elmphant\Tui\Widget\Structure\TabBar;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final readonly class ExposedGlobalApp extends CopyableTestApp
{
    public function view(): Widget
    {
        return Text::new('noop');
    }

    public function exposeGlobalKeybindings(): Keybindings
    {
        return $this->globalKeybindings();
    }
}

/**
 * Records whether a 'custom_action' binding fires on messages sent to it.
 * Has no default binding for the action; relies on global to supply one.
 */
final readonly class GlobalObserverWidget implements Focusable, KeybindingsAware
{
    use Cloneable;
    use KeybindingsAwareBehavior;

    public function __construct(
        private bool $focused = false,
        public int $customActionTriggered = 0,
        private ?Keybindings $customKeybindings = null,
        private ?Keybindings $globalKeybindings = null,
    ) {
    }

    public static function defaultKeybindings(): Keybindings
    {
        return new Keybindings(['custom_action' => []]);
    }

    public function isFocused(): bool
    {
        return $this->focused;
    }

    public function withFocus(bool $focused): static
    {
        return $this->cloneWith(focused: $focused);
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::exact(1), Constraint::exact(1));
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        if ($message instanceof KeyMsg
            && $this->effectiveKeybindings()->matches($message, 'custom_action')
        ) {
            return new UpdateResult($this->cloneWith(customActionTriggered: $this->customActionTriggered + 1));
        }

        return new UpdateResult($this);
    }
}

final readonly class GlobalCustomApp extends CopyableTestApp
{
    public function __construct(
        public GlobalObserverWidget $widget = new GlobalObserverWidget(focused: true),
    ) {
    }

    public function view(): Widget
    {
        return Component::bind(
            ComponentId::named('global-observer'),
            $this->widget,
            static fn (self $app, GlobalObserverWidget $widget): self => $app->cloneWith(widget: $widget),
        );
    }

    protected function globalKeybindings(): Keybindings
    {
        return new Keybindings(['custom_action' => ['x']]);
    }
}

final readonly class VimModeApp extends CopyableTestApp
{
    public function __construct(
        public Select $select = new Select(options: ['a', 'b', 'c'], focused: true),
    ) {
    }

    public function view(): Widget
    {
        return Component::bind(
            ComponentId::named('vim-select'),
            $this->select,
            static fn (self $app, Select $select): self => $app->cloneWith(select: $select),
        );
    }

    protected function globalKeybindings(): Keybindings
    {
        return VimMode::keybindings();
    }
}

final class GlobalKeybindingsDispatchTest extends TestCase
{
    #[Test]
    public function defaultAppGlobalKeybindingsIsEmptySingleton(): void
    {
        $app = new ExposedGlobalApp();

        self::assertSame(Keybindings::empty(), $app->exposeGlobalKeybindings());
    }

    #[Test]
    public function appWrapsFocusableBeforeKeyDispatch(): void
    {
        $app = new GlobalCustomApp();
        $result = $app->update(new KeyMsg('x'), Rect::fromSize(80, 24));

        /** @var GlobalCustomApp $updated */
        $updated = $result->model;
        self::assertSame(1, $updated->widget->customActionTriggered);
    }

    #[Test]
    public function appWrapsFocusableBeforeMouseDispatch(): void
    {
        $app = new GlobalCustomApp();
        $result = $app->update(
            new MouseMsg(0, 0, MouseAction::Press, MouseButton::Left),
            Rect::fromSize(80, 24),
        );
        self::assertInstanceOf(GlobalCustomApp::class, $result->model);
    }

    #[Test]
    public function appWrapsFocusableOnTickBroadcast(): void
    {
        $app = new GlobalCustomApp();
        $result = $app->update(new TickMsg(0.0), Rect::fromSize(80, 24));
        self::assertInstanceOf(GlobalCustomApp::class, $result->model);
    }

    #[Test]
    public function appDispatchOnTabKeyWrapsFocusable(): void
    {
        $app = new GlobalCustomApp();
        $result = $app->update(new KeyMsg(Key::Tab), Rect::fromSize(80, 24));
        self::assertInstanceOf(GlobalCustomApp::class, $result->model);
    }

    #[Test]
    public function panelPropagatesGlobalAtDelegationSite(): void
    {
        // Child observer widget fires on 'x' via the global binding.
        $child = new GlobalObserverWidget(focused: true);
        $panel = Panel::new('T')->add($child)->withFocus(true);

        // Simulate App wrapping Panel with globals.
        $global = new Keybindings(['custom_action' => ['x']]);
        $panel = $panel->withGlobalKeybindings($global);

        // Panel delegates 'x' to its focused child. Child must see global binding.
        $result = $panel->update(new KeyMsg('x'), Rect::fromSize(20, 5));

        $newPanel = $result->model;
        self::assertInstanceOf(Panel::class, $newPanel);
        $children = [...$newPanel->children()];

        self::assertSame(1, $children[0]->customActionTriggered);
    }

    #[Test]
    public function tabBarPropagatesGlobalAtDelegationSite(): void
    {
        $child = new GlobalObserverWidget(focused: true);
        $tabBar = TabBar::new()->addTab('One')->add($child)->withFocus(true);

        $global = new Keybindings(['custom_action' => ['x']]);
        $tabBar = $tabBar->withGlobalKeybindings($global);

        $result = $tabBar->update(new KeyMsg('x'), Rect::fromSize(30, 10));

        $newTabBar = $result->model;
        self::assertInstanceOf(TabBar::class, $newTabBar);
        $children = [...$newTabBar->children()];

        self::assertSame(1, $children[0]->customActionTriggered);
    }

    #[Test]
    public function vimModeAppliedAsGlobalReachesSelect(): void
    {
        $app = new VimModeApp();

        $result = $app->update(new KeyMsg('j'), Rect::fromSize(40, 10));
        /** @var VimModeApp $updated */
        $updated = $result->model;
        self::assertSame(1, $updated->select->cursor());

        // Default 'down' still works — additive semantics
        $result2 = $app->update(new KeyMsg(Key::Down), Rect::fromSize(40, 10));
        /** @var VimModeApp $updated2 */
        $updated2 = $result2->model;
        self::assertSame(1, $updated2->select->cursor());
    }
}
