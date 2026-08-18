<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Core;

use Elmphant\Tui\Core\BatchCmd;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\ExecCmd;
use Elmphant\Tui\Core\FocusCmd;
use Elmphant\Tui\Core\FocusState;
use Elmphant\Tui\Core\QuitCmd;
use Elmphant\Tui\Core\TickCmd;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Input\KeybindingsAware;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Layout;
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
use Elmphant\Tui\Tests\Core\Support\TestComponents;
use Elmphant\Tui\Tests\Support\TestMessage;
use Elmphant\Tui\Widget\Focusable;
use Elmphant\Tui\Widget\Panel;
use Elmphant\Tui\Widget\Text;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AppFocusableTest extends TestCase
{
    #[Test]
    public function aTabNoWidgetTookAsksForTheNextComponent(): void
    {
        $app = new ThreePanelApp();

        $result = $app->update(new KeyMsg(Key::Tab), Rect::fromSize(80, 24), new FocusState(ComponentId::named('second-panel')));

        self::assertEquals(
            ComponentId::named('third-panel'),
            self::keyboardLandsOn($result->command, $app->focusableIds(), 'second-panel'),
        );
    }

    #[Test]
    public function keyDelegatesToFocusedWidget(): void
    {
        $app = TwoPanelApp::create();
        $originalLeft = $app->left;

        $result = $app->update(new KeyMsg(Key::Down), Rect::fromSize(80, 24));
        self::assertInstanceOf(TwoPanelApp::class, $result->model);
        self::assertNull($result->command);
    }

    #[Test]
    public function onKeyInterceptsBeforeDelegation(): void
    {
        $app = InterceptApp::create();
        self::assertFalse($app->toggled);

        $result = $app->update(new KeyMsg(' '), Rect::fromSize(80, 24));
        self::assertInstanceOf(InterceptApp::class, $result->model);
        self::assertTrue($result->model->toggled);
    }

    #[Test]
    public function noAutoQuitOnRegularKeysWithFocusables(): void
    {
        $app = TwoPanelApp::create();

        $result = $app->update(new KeyMsg('q'), Rect::fromSize(80, 24));
        self::assertNotInstanceOf(QuitCmd::class, $result->command);
    }

    #[Test]
    public function theQuitBindingStillQuitsWhileNoFocusableHoldsFocus(): void
    {
        $app = new TwoPanelApp(
            left: Panel::new('Left')->withFocus(false),
            right: Panel::new('Right')->withFocus(false),
        );

        $result = $app->update(new KeyMsg('q'), Rect::fromSize(80, 24));
        self::assertInstanceOf(QuitCmd::class, $result->command);
    }

    #[Test]
    public function ctrlCStillQuitsWithFocusables(): void
    {
        $app = TwoPanelApp::create();

        $result = $app->update(new KeyMsg('c', ctrl: true), Rect::fromSize(80, 24));
        self::assertInstanceOf(QuitCmd::class, $result->command);
    }

    #[Test]
    public function applicationQuitShortcutWorksWithFocusables(): void
    {
        $app = TwoPanelApp::create();

        $result = $app->update(new KeyMsg('q', ctrl: true), Rect::fromSize(80, 24));

        self::assertInstanceOf(QuitCmd::class, $result->command);
    }

    #[Test]
    public function applicationActionsAreDispatchedBeforeTheFocusedWidget(): void
    {
        $app = ApplicationShortcutApp::create();

        $result = $app->update(new KeyMsg(Key::F1), Rect::fromSize(80, 24));

        self::assertInstanceOf(ApplicationShortcutApp::class, $result->model);
        self::assertSame('help', $result->model->lastAction);
        self::assertSame(0, $result->model->widget->count);
    }

    #[Test]
    public function unhandledApplicationActionsAreSafelyConsumed(): void
    {
        $app = UnhandledApplicationShortcutApp::create();

        $result = $app->update(new KeyMsg(Key::F1), Rect::fromSize(80, 24));

        self::assertSame($app, $result->model);
        self::assertNull($result->command);
        self::assertSame(0, $result->model->widget->count);
    }

    #[Test]
    public function tickBroadcastToAllFocusables(): void
    {
        $app = TwoPanelApp::create();

        $result = $app->update(new TickMsg(microtime(true)), Rect::fromSize(80, 24));
        self::assertInstanceOf(TwoPanelApp::class, $result->model);
        self::assertNull($result->command);
    }

    #[Test]
    public function focusablesEmptyFallsBackToLayerOne(): void
    {
        $app = new SimpleCounterApp();

        $result = $app->update(new KeyMsg('q'), Rect::fromSize(80, 24));
        self::assertInstanceOf(QuitCmd::class, $result->command);
    }

    #[Test]
    public function tabDelegatedToFocusedWidgetWithInternalFocusables(): void
    {
        $innerLeft = Panel::new('Inner1')->withFocus(true);
        $innerRight = Panel::new('Inner2');
        $outerLeft = Panel::new('Outer')->add($innerLeft)->add($innerRight)->withFocus(true);
        $outerRight = Panel::new('Right');

        $app = new TwoPanelApp(left: $outerLeft, right: $outerRight);
        $result = $app->update(new KeyMsg(Key::Tab), Rect::fromSize(80, 24));

        self::assertInstanceOf(TwoPanelApp::class, $result->model);
        self::assertNotSame($app->left, $result->model->left);
    }

    #[Test]
    public function keyDelegatesToFocusedAndReflectsWidgetChange(): void
    {
        $app = new CountingFocusableApp(
            counter: new CountingFocusable(focused: true),
        );

        $result = $app->update(new KeyMsg('x'), Rect::fromSize(80, 24));
        self::assertInstanceOf(CountingFocusableApp::class, $result->model);
        self::assertSame(1, $result->model->counter->count);
    }

    #[Test]
    public function keyDelegatedToFocusedReturnsCommandFromWidget(): void
    {
        $app = new CountingFocusableApp(
            counter: new CountingFocusable(focused: true),
        );

        $result = $app->update(new KeyMsg('c'), Rect::fromSize(80, 24));
        self::assertInstanceOf(ExecCmd::class, $result->command);
    }

    #[Test]
    public function keyToUnfocusedAppReturnsUnchanged(): void
    {
        $app = new TwoPanelApp(
            left: Panel::new('Left')->withFocus(false),
            right: Panel::new('Right')->withFocus(false),
        );

        $result = $app->update(new KeyMsg('x'), Rect::fromSize(80, 24));
        self::assertSame($app, $result->model);
        self::assertNull($result->command);
    }

    #[Test]
    public function mouseDelegatesToAllFocusablesAndUpdatesFocusByClick(): void
    {
        $app = TwoPanelApp::create();
        self::assertTrue($app->left->isFocused());
        self::assertFalse($app->right->isFocused());

        $result = $app->update(
            new MouseMsg(60, 5, MouseAction::Press, MouseButton::Left),
            Rect::fromSize(80, 24),
        );
        self::assertInstanceOf(TwoPanelApp::class, $result->model);
    }

    #[Test]
    public function mouseNonClickDoesNotChangeFocus(): void
    {
        $app = TwoPanelApp::create();

        $result = $app->update(
            new MouseMsg(60, 5, MouseAction::Motion, MouseButton::None),
            Rect::fromSize(80, 24),
        );
        self::assertInstanceOf(TwoPanelApp::class, $result->model);
    }

    #[Test]
    public function anUnmountedFocusedWidgetIsPassive(): void
    {
        $app = new DetachedRectCapturingApp(
            widget: new RectCapturingFocusable(focused: true),
        );

        $result = $app->update(new KeyMsg('x'), Rect::fromSize(120, 40));

        self::assertSame($app, $result->model);
        self::assertSame(0, $result->model->widget->capturedWidth);
        self::assertSame(0, $result->model->widget->capturedHeight);
    }

    #[Test]
    public function tabIgnoresAnUnmountedFocusable(): void
    {
        $app = DetachedFocusableApp::create();

        $result = $app->update(new KeyMsg(Key::Tab), Rect::fromSize(80, 24));

        self::assertSame($app, $result->model);
        self::assertTrue($result->model->panel->isFocused());
    }

    #[Test]
    public function mouseIgnoresAnUnmountedFocusable(): void
    {
        $app = DetachedFocusableApp::create();

        $result = $app->update(
            new MouseMsg(5, 5, MouseAction::Press, MouseButton::Left),
            Rect::fromSize(80, 24),
        );

        self::assertSame($app, $result->model);
        self::assertTrue($result->model->panel->isFocused());
    }

    #[Test]
    public function onMessageInLayerTwoReturnsResult(): void
    {
        $app = TwoPanelApp::create();
        $result = $app->update(new TestMessage('custom-message'), Rect::fromSize(80, 24));
        self::assertInstanceOf(TwoPanelApp::class, $result->model);
        self::assertSame($app, $result->model);
    }

    #[Test]
    public function aClickAsksForTheKeyboardOfThePanelItLandedIn(): void
    {
        $app = TwoPanelApp::create();

        $result = $app->update(
            new MouseMsg(79, 5, MouseAction::Press, MouseButton::Left),
            Rect::fromSize(80, 24),
        );

        self::assertEquals(Cmd::focus(ComponentId::named('right-panel')), $result->command);
    }

    #[Test]
    public function clickInsideLeftPanelKeepsFocusOnLeft(): void
    {
        $app = TwoPanelApp::create();
        self::assertTrue($app->left->isFocused());

        $result = $app->update(
            new MouseMsg(5, 5, MouseAction::Press, MouseButton::Left),
            Rect::fromSize(80, 24),
        );

        self::assertInstanceOf(TwoPanelApp::class, $result->model);
        self::assertTrue($result->model->left->isFocused());
        self::assertFalse($result->model->right->isFocused());
    }

    #[Test]
    public function findFocusedKeyReturnsFocusedWidgetKey(): void
    {
        $app = new CountingFocusableApp(
            counter: new CountingFocusable(focused: true),
        );

        $result = $app->update(new KeyMsg('x'), Rect::fromSize(80, 24));
        self::assertSame(1, $result->model->counter->count);
    }

    #[Test]
    public function findFocusedKeySkipsUnfocusedWidgets(): void
    {
        $app = new TwoCountingFocusableApp(
            left: new CountingFocusable(count: 0, focused: false),
            right: new CountingFocusable(count: 0, focused: true),
        );

        $result = $app->update(new KeyMsg('x'), Rect::fromSize(80, 24));
        self::assertInstanceOf(TwoCountingFocusableApp::class, $result->model);
        self::assertSame(0, $result->model->left->count);
        self::assertSame(1, $result->model->right->count);
    }

    #[Test]
    public function findFocusedKeyReturnsNullWhenNoneFocused(): void
    {
        $app = new CountingFocusableApp(
            counter: new CountingFocusable(focused: false),
        );

        $result = $app->update(new KeyMsg('x'), Rect::fromSize(80, 24));
        self::assertSame(0, $result->model->counter->count);
    }

    #[Test]
    public function delegateToFocusedReturnsUnchangedWhenNoFocus(): void
    {
        $app = new TwoPanelApp(
            left: Panel::new('Left')->withFocus(false),
            right: Panel::new('Right')->withFocus(false),
        );

        $result = $app->update(new KeyMsg('x'), Rect::fromSize(80, 24));
        self::assertSame($app, $result->model);
    }

    #[Test]
    public function delegateToFocusedReflectsChangedWidget(): void
    {
        $app = new CountingFocusableApp(
            counter: new CountingFocusable(focused: true),
        );

        $result = $app->update(new KeyMsg('x'), Rect::fromSize(80, 24));
        self::assertNotSame($app, $result->model);
        self::assertSame(1, $result->model->counter->count);
    }

    #[Test]
    public function delegateToFocusedPreservesCommandWhenWidgetUnchanged(): void
    {
        $app = new UnchangedFocusableApp(
            widget: new UnchangedFocusable(focused: true),
        );

        $result = $app->update(new KeyMsg('x'), Rect::fromSize(80, 24));
        self::assertSame($app, $result->model);
        self::assertNull($result->command);
    }

    #[Test]
    public function updateDefaultRectPassedToFocusable(): void
    {
        $app = new RectCapturingFocusableApp(
            widget: new RectCapturingFocusable(focused: true),
        );

        $result = $app->update(new KeyMsg('x'), Rect::fromSize(80, 24));
        self::assertInstanceOf(RectCapturingFocusableApp::class, $result->model);
        self::assertSame(80, $result->model->widget->capturedWidth);
        self::assertSame(24, $result->model->widget->capturedHeight);
    }

    #[Test]
    public function focusableRectsReturnsAllKeysNotJustFirst(): void
    {
        $app = new TwoRectCapturingApp(
            left: new RectCapturingFocusable(focused: false),
            right: new RectCapturingFocusable(focused: true),
        );

        $result = $app->update(new KeyMsg('x'), Rect::fromSize(80, 24));
        self::assertInstanceOf(TwoRectCapturingApp::class, $result->model);
        self::assertSame(40, $result->model->right->capturedWidth);
    }

    #[Test]
    public function mouseClickDelegatesToFocusableAndPreservesCountWithFocus(): void
    {
        $app = new ClickTrackingFocusableApp(
            widget: new ClickTrackingFocusable(count: 0, focused: false),
            other: new UnchangedFocusable(focused: true),
        );

        $result = $app->update(
            new MouseMsg(5, 5, MouseAction::Press, MouseButton::Left),
            Rect::fromSize(80, 24),
        );

        self::assertInstanceOf(ClickTrackingFocusableApp::class, $result->model);
        self::assertSame(1, $result->model->widget->count);
        self::assertEquals(Cmd::focus(ComponentId::named('click-tracker')), $result->command);
    }

    #[Test]
    public function mouseClickNoStateChangeReturnsSameInstance(): void
    {
        $app = new TwoUnchangedFocusableApp(
            left: new UnchangedFocusable(focused: true),
            right: new UnchangedFocusable(focused: false),
        );

        $result = $app->update(
            new MouseMsg(5, 5, MouseAction::Press, MouseButton::Left),
            Rect::fromSize(80, 24),
        );

        self::assertSame($app, $result->model);
    }

    #[Test]
    public function tabConsumedByFocusableDoesNotCycleFocus(): void
    {
        $app = new TabConsumingApp(
            widget: new TabConsumingFocusable(tabCount: 0, focused: true),
            other: new UnchangedFocusable(focused: false),
        );

        $result = $app->update(new KeyMsg(Key::Tab), Rect::fromSize(80, 24));

        self::assertInstanceOf(TabConsumingApp::class, $result->model);
        self::assertSame(1, $result->model->widget->tabCount);
        self::assertTrue($result->model->widget->isFocused());
        self::assertFalse($result->model->other->isFocused());
    }

    #[Test]
    public function mouseCommandBatchesAppAndWidgetCommands(): void
    {
        $app = new MouseCommandFocusableApp(
            widget: new CommandOnClickFocusable(focused: true),
        );

        $result = $app->update(
            new MouseMsg(5, 5, MouseAction::Press, MouseButton::Left),
            Rect::fromSize(80, 24),
        );

        self::assertInstanceOf(BatchCmd::class, $result->command);
        self::assertCount(3, $result->command->commands);
        self::assertInstanceOf(TickCmd::class, $result->command->commands[0]);
        self::assertInstanceOf(ExecCmd::class, $result->command->commands[1]);
        self::assertInstanceOf(FocusCmd::class, $result->command->commands[2], 'the click moves the keyboard too');
    }

    #[Test]
    public function mouseDelegateWithNoCommandsReturnsNull(): void
    {
        $app = new TwoUnchangedFocusableApp(
            left: new UnchangedFocusable(focused: true),
            right: new UnchangedFocusable(focused: false),
        );

        $result = $app->update(
            new MouseMsg(5, 5, MouseAction::Motion, MouseButton::None),
            Rect::fromSize(80, 24),
        );

        self::assertNull($result->command);
    }

    #[Test]
    public function aClickCarriesWhatTheWidgetAskedForBesideTheFocusMove(): void
    {
        $app = new SingleCommandOnClickApp(
            widget: new CommandOnClickFocusable(focused: true),
        );

        $result = $app->update(
            new MouseMsg(5, 5, MouseAction::Press, MouseButton::Left),
            Rect::fromSize(80, 24),
        );

        self::assertInstanceOf(BatchCmd::class, $result->command);
        self::assertCount(2, $result->command->commands);
        self::assertInstanceOf(ExecCmd::class, $result->command->commands[0]);
        self::assertInstanceOf(FocusCmd::class, $result->command->commands[1]);
    }

    #[Test]
    public function handleTabPreservesCommandEvenWhenModelUnchanged(): void
    {
        $app = new TabCommandOnlyApp(
            widget: new TabCommandOnlyFocusable(focused: true),
            other: new UnchangedFocusable(focused: false),
        );

        $result = $app->update(new KeyMsg(Key::Tab), Rect::fromSize(80, 24));

        self::assertInstanceOf(TabCommandOnlyApp::class, $result->model);
        self::assertNotNull($result->command);
        self::assertTrue($result->model->widget->isFocused());
        self::assertFalse($result->model->other->isFocused());
    }

    #[Test]
    public function onKeyReturningNoOpUpdateResultStillDelegatesToFocused(): void
    {
        $app = new NoOpUpdateResultApp(
            counter: new CountingFocusable(focused: true),
        );

        $result = $app->update(new KeyMsg('x'), Rect::fromSize(80, 24));

        self::assertInstanceOf(NoOpUpdateResultApp::class, $result->model);
        self::assertSame(1, $result->model->counter->count);
    }

    #[Test]
    public function onKeyReturningUpdateResultWithChangedModelShortCircuits(): void
    {
        $app = new ToggleUpdateResultApp(
            counter: new CountingFocusable(focused: true),
        );

        $result = $app->update(new KeyMsg('x'), Rect::fromSize(80, 24));

        self::assertInstanceOf(ToggleUpdateResultApp::class, $result->model);
        self::assertTrue($result->model->toggled);
        self::assertSame(0, $result->model->counter->count);
    }

    #[Test]
    public function onKeyReturningUpdateResultWithCommandShortCircuits(): void
    {
        $app = new CommandOnlyUpdateResultApp(
            counter: new CountingFocusable(focused: true),
        );

        $result = $app->update(new KeyMsg('x'), Rect::fromSize(80, 24));

        self::assertInstanceOf(CommandOnlyUpdateResultApp::class, $result->model);
        self::assertSame(0, $result->model->counter->count);
        self::assertInstanceOf(QuitCmd::class, $result->command);
    }

    #[Test]
    public function mouseChangeWithWidgetOverridePreservesBoth(): void
    {
        $app = new MouseTrackingFocusableApp(
            mouseTracked: false,
            widget: new ClickTrackingFocusable(count: 0, focused: false),
            other: new UnchangedFocusable(focused: true),
        );

        $result = $app->update(
            new MouseMsg(5, 5, MouseAction::Press, MouseButton::Left),
            Rect::fromSize(80, 24),
        );

        self::assertInstanceOf(MouseTrackingFocusableApp::class, $result->model);
        self::assertTrue($result->model->mouseTracked);
        self::assertSame(1, $result->model->widget->count);
    }

    #[Test]
    public function mouseDelegationUsesGlobalKeybindingsFromUpdatedModel(): void
    {
        $oldBindings = new Keybindings(['old' => ['x']]);
        $newBindings = new Keybindings(['new' => ['y']]);

        $app = new GlobalKeybindingsToggleApp(
            widget: new BindingCapturingFocusable(focused: true),
            currentBindings: $oldBindings,
            nextBindings: $newBindings,
        );

        $result = $app->update(
            new MouseMsg(5, 5, MouseAction::Press, MouseButton::Left),
            Rect::fromSize(80, 24),
        );

        self::assertInstanceOf(GlobalKeybindingsToggleApp::class, $result->model);
        self::assertSame($newBindings, $result->model->widget->capturedGlobalBindings);
    }

    #[Test]
    public function tickBroadcastUsesGlobalKeybindingsFromUpdatedModel(): void
    {
        $oldBindings = new Keybindings(['old' => ['x']]);
        $newBindings = new Keybindings(['new' => ['y']]);

        $app = new GlobalKeybindingsTickToggleApp(
            widget: new BindingCapturingFocusable(focused: true),
            currentBindings: $oldBindings,
            nextBindings: $newBindings,
        );

        $result = $app->update(new TickMsg(microtime(true)), Rect::fromSize(80, 24));

        self::assertInstanceOf(GlobalKeybindingsTickToggleApp::class, $result->model);
        self::assertSame($newBindings, $result->model->widget->capturedGlobalBindings);
    }

    #[Test]
    public function mouseDelegationUsesNewFocusablesAfterOnMouseReplace(): void
    {
        $app = MouseSwapApp::create();
        $screen = Rect::fromSize(80, 24);

        $click = new MouseMsg(50, 5, MouseAction::Press, MouseButton::Left);
        $result = $app->update($click, $screen);

        /** @var MouseSwapApp $updated */
        $updated = $result->model;

        self::assertTrue($updated->swapped, 'onMouse should have triggered');
        self::assertSame('replaced-right', $updated->right->marker, 'right should be the replaced widget, not the original');
        self::assertEquals(
            Cmd::focus(ComponentId::named('right-marker')),
            $result->command,
            'the keyboard is asked for on the widget that replaced the one clicked',
        );
    }

    /**
     * @param list<ComponentId> $order
     */
    private static function keyboardLandsOn(?Cmd $command, array $order, ?string $from): ?ComponentId
    {
        self::assertInstanceOf(FocusCmd::class, $command);

        $start = new FocusState(null === $from ? null : ComponentId::named($from));

        return $command->applyTo($start, $order)->focused();
    }
}

final readonly class TwoPanelApp extends CopyableTestApp
{
    public function __construct(
        public Panel $left = new Panel(),
        public Panel $right = new Panel(),
    ) {
    }

    public static function create(): self
    {
        return new self(
            left: Panel::new('Left')->withFocus(true),
            right: Panel::new('Right'),
        );
    }

    public function view(): Widget
    {
        return Layout::horizontal(
            TestComponents::bind('left-panel', 'left', $this->left),
            TestComponents::bind('right-panel', 'right', $this->right),
        );
    }

    protected function quitBindings(): Keybindings
    {
        return new Keybindings(['quit' => ['q']]);
    }
}

final readonly class InterceptApp extends CopyableTestApp
{
    public function __construct(
        public Panel $left = new Panel(),
        public Panel $right = new Panel(),
        public bool $toggled = false,
    ) {
    }

    public static function create(): self
    {
        return new self(
            left: Panel::new('Left')->withFocus(true),
            right: Panel::new('Right'),
        );
    }

    public function view(): Widget
    {
        return Layout::horizontal(
            TestComponents::bind('left-panel', 'left', $this->left),
            TestComponents::bind('right-panel', 'right', $this->right),
        );
    }

    protected function onKey(KeyMsg $message, Rect $screen): UpdateResult
    {
        return match ($message->key) {
            ' ' => UpdateResult::changed($this->cloneWith(toggled: !$this->toggled)),
            default => UpdateResult::keep($this),
        };
    }
}

final readonly class ApplicationShortcutApp extends CopyableTestApp
{
    public function __construct(
        public CountingFocusable $widget,
        public ?string $lastAction = null,
    ) {
    }

    public static function create(): self
    {
        return new self(new CountingFocusable(focused: true));
    }

    public function view(): Widget
    {
        return TestComponents::bind('shortcut-widget', 'widget', $this->widget);
    }

    protected function applicationKeybindings(): Keybindings
    {
        return new Keybindings([
            'quit' => ['ctrl+q'],
            'help' => ['f1'],
        ]);
    }

    protected function onAction(string $action, KeyMsg $message): UpdateResult
    {
        return UpdateResult::changed($this->cloneWith(lastAction: $action));
    }
}

final readonly class UnhandledApplicationShortcutApp extends CopyableTestApp
{
    public function __construct(public CountingFocusable $widget)
    {
    }

    public static function create(): self
    {
        return new self(new CountingFocusable(focused: true));
    }

    public function view(): Widget
    {
        return TestComponents::bind('unhandled-shortcut-widget', 'widget', $this->widget);
    }

    protected function applicationKeybindings(): Keybindings
    {
        return new Keybindings(['help' => ['f1']]);
    }
}

final readonly class SimpleCounterApp extends CopyableTestApp
{
    public function __construct(public int $count = 0)
    {
    }

    public function view(): Widget
    {
        return Text::new("Count: {$this->count}");
    }

    protected function quitBindings(): Keybindings
    {
        return new Keybindings(['quit' => ['q']]);
    }

    protected function onKey(KeyMsg $message, Rect $screen): UpdateResult
    {
        return match ($message->key) {
            'k' => UpdateResult::changed($this->cloneWith(count: $this->count + 1)),
            default => UpdateResult::keep($this),
        };
    }
}

final readonly class ThreePanelApp extends CopyableTestApp
{
    public function __construct(
        public Panel $first = new Panel(),
        public Panel $second = new Panel(),
        public Panel $third = new Panel(),
    ) {
    }

    public function view(): Widget
    {
        return Layout::horizontal(
            TestComponents::bind('first-panel', 'first', $this->first),
            TestComponents::bind('second-panel', 'second', $this->second),
            TestComponents::bind('third-panel', 'third', $this->third),
        );
    }
}

final readonly class DetachedFocusableApp extends CopyableTestApp
{
    public function __construct(
        public Panel $panel = new Panel(),
    ) {
    }

    public static function create(): self
    {
        return new self(panel: Panel::new('Detached')->withFocus(true));
    }

    public function view(): Widget
    {
        return Text::new('no focusable in tree');
    }
}

final class BindingCapturingFocusable implements Focusable, KeybindingsAware
{
    public ?Keybindings $capturedGlobalBindings = null;

    public function __construct(
        private bool $focused = false,
    ) {
    }

    public function isFocused(): bool
    {
        return $this->focused;
    }

    public function withFocus(bool $focused): static
    {
        return new self(focused: $focused);
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::flex());
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return new UpdateResult($this);
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
    }

    public static function defaultKeybindings(): Keybindings
    {
        return Keybindings::empty();
    }

    public function effectiveKeybindings(): Keybindings
    {
        return Keybindings::empty();
    }

    public function withKeybindings(?Keybindings $keybindings): static
    {
        return $this;
    }

    public function withGlobalKeybindings(?Keybindings $keybindings): static
    {
        $this->capturedGlobalBindings = $keybindings;

        return $this;
    }
}

final readonly class GlobalKeybindingsToggleApp extends CopyableTestApp
{
    public function __construct(
        public BindingCapturingFocusable $widget,
        public Keybindings $currentBindings,
        public Keybindings $nextBindings,
    ) {
    }

    public function view(): Widget
    {
        return TestComponents::bind('binding-capture', 'widget', $this->widget);
    }

    protected function onMouse(MouseMsg $message, Rect $screen): UpdateResult
    {
        return UpdateResult::changed($this->cloneWith(currentBindings: $this->nextBindings));
    }

    protected function globalKeybindings(): Keybindings
    {
        return $this->currentBindings;
    }

    protected function quitBindings(): Keybindings
    {
        return new Keybindings(['quit' => []]);
    }
}

final readonly class GlobalKeybindingsTickToggleApp extends CopyableTestApp
{
    public function __construct(
        public BindingCapturingFocusable $widget,
        public Keybindings $currentBindings,
        public Keybindings $nextBindings,
    ) {
    }

    public function view(): Widget
    {
        return TestComponents::bind('binding-capture', 'widget', $this->widget);
    }

    protected function onTick(TickMsg $message, Rect $screen): UpdateResult
    {
        return UpdateResult::changed($this->cloneWith(currentBindings: $this->nextBindings));
    }

    protected function globalKeybindings(): Keybindings
    {
        return $this->currentBindings;
    }

    protected function quitBindings(): Keybindings
    {
        return new Keybindings(['quit' => []]);
    }
}

final readonly class CountingFocusable implements Focusable
{
    public function __construct(
        public int $count = 0,
        private bool $focused = false,
    ) {
    }

    public function isFocused(): bool
    {
        return $this->focused;
    }

    public function withFocus(bool $focused): static
    {
        return new self(count: $this->count, focused: $focused);
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::flex());
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        if ($message instanceof KeyMsg && $message->key === 'c') {
            return new UpdateResult(
                new self(count: $this->count + 1, focused: $this->focused),
                Cmd::exec(['echo', 'command']),
            );
        }

        if ($message instanceof KeyMsg) {
            return new UpdateResult(new self(count: $this->count + 1, focused: $this->focused));
        }

        return new UpdateResult($this);
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
    }
}

final readonly class NoOpUpdateResultApp extends CopyableTestApp
{
    public function __construct(
        public CountingFocusable $counter,
    ) {
    }

    public function view(): Widget
    {
        return TestComponents::bind('counting-widget', 'counter', $this->counter);
    }

    protected function onKey(KeyMsg $message, Rect $screen): UpdateResult
    {
        return UpdateResult::keep($this);
    }

    protected function quitBindings(): Keybindings
    {
        return new Keybindings(['quit' => []]);
    }
}

final readonly class ToggleUpdateResultApp extends CopyableTestApp
{
    public function __construct(
        public CountingFocusable $counter,
        public bool $toggled = false,
    ) {
    }

    public function view(): Widget
    {
        return TestComponents::bind('counting-widget', 'counter', $this->counter);
    }

    protected function onKey(KeyMsg $message, Rect $screen): UpdateResult
    {
        return UpdateResult::changed($this->cloneWith(toggled: true));
    }

    protected function quitBindings(): Keybindings
    {
        return new Keybindings(['quit' => []]);
    }
}

final readonly class CommandOnlyUpdateResultApp extends CopyableTestApp
{
    public function __construct(
        public CountingFocusable $counter,
    ) {
    }

    public function view(): Widget
    {
        return TestComponents::bind('counting-widget', 'counter', $this->counter);
    }

    protected function onKey(KeyMsg $message, Rect $screen): UpdateResult
    {
        return UpdateResult::changed($this, Cmd::quit());
    }

    protected function quitBindings(): Keybindings
    {
        return new Keybindings(['quit' => []]);
    }
}

final readonly class CountingFocusableApp extends CopyableTestApp
{
    public function __construct(
        public CountingFocusable $counter,
    ) {
    }

    public function view(): Widget
    {
        return TestComponents::bind('counting-widget', 'counter', $this->counter);
    }

    protected function quitBindings(): Keybindings
    {
        return new Keybindings(['quit' => []]);
    }
}

final readonly class RectCapturingFocusable implements Focusable
{
    public function __construct(
        public int $capturedWidth = 0,
        public int $capturedHeight = 0,
        private bool $focused = false,
    ) {
    }

    public function isFocused(): bool
    {
        return $this->focused;
    }

    public function withFocus(bool $focused): static
    {
        return new self(capturedWidth: $this->capturedWidth, capturedHeight: $this->capturedHeight, focused: $focused);
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::flex());
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        if ($message instanceof KeyMsg) {
            return new UpdateResult(new self(
                capturedWidth: $area->width,
                capturedHeight: $area->height,
                focused: $this->focused,
            ));
        }

        return new UpdateResult($this);
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
    }
}

final readonly class DetachedRectCapturingApp extends CopyableTestApp
{
    public function __construct(
        public RectCapturingFocusable $widget,
    ) {
    }

    public function view(): Widget
    {
        return Text::new('detached');
    }

    protected function quitBindings(): Keybindings
    {
        return new Keybindings(['quit' => []]);
    }
}

final readonly class UnchangedFocusable implements Focusable
{
    public function __construct(
        private bool $focused = false,
    ) {
    }

    public function isFocused(): bool
    {
        return $this->focused;
    }

    public function withFocus(bool $focused): static
    {
        return new self(focused: $focused);
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::flex());
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return new UpdateResult($this);
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
    }
}

final readonly class UnchangedFocusableApp extends CopyableTestApp
{
    public function __construct(
        public UnchangedFocusable $widget,
    ) {
    }

    public function view(): Widget
    {
        return TestComponents::bind('unchanged-widget', 'widget', $this->widget);
    }

    protected function quitBindings(): Keybindings
    {
        return new Keybindings(['quit' => []]);
    }
}

final readonly class RectCapturingFocusableApp extends CopyableTestApp
{
    public function __construct(
        public RectCapturingFocusable $widget,
    ) {
    }

    public function view(): Widget
    {
        return TestComponents::bind('rect-capture', 'widget', $this->widget);
    }

    protected function quitBindings(): Keybindings
    {
        return new Keybindings(['quit' => []]);
    }
}

final readonly class TwoRectCapturingApp extends CopyableTestApp
{
    public function __construct(
        public RectCapturingFocusable $left,
        public RectCapturingFocusable $right,
    ) {
    }

    public function view(): Widget
    {
        return Layout::horizontal(
            TestComponents::bind('left-capture', 'left', $this->left),
            TestComponents::bind('right-capture', 'right', $this->right),
        );
    }

    protected function quitBindings(): Keybindings
    {
        return new Keybindings(['quit' => []]);
    }
}

final readonly class TwoCountingFocusableApp extends CopyableTestApp
{
    public function __construct(
        public CountingFocusable $left,
        public CountingFocusable $right,
    ) {
    }

    public function view(): Widget
    {
        return Layout::horizontal(
            TestComponents::bind('left-counter', 'left', $this->left),
            TestComponents::bind('right-counter', 'right', $this->right),
        );
    }

    protected function quitBindings(): Keybindings
    {
        return new Keybindings(['quit' => []]);
    }
}

final readonly class ClickTrackingFocusable implements Focusable
{
    public function __construct(
        public int $count = 0,
        private bool $focused = false,
    ) {
    }

    public function isFocused(): bool
    {
        return $this->focused;
    }

    public function withFocus(bool $focused): static
    {
        return new self(count: $this->count, focused: $focused);
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::flex());
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        if ($message instanceof MouseMsg && $message->isClick()) {
            return new UpdateResult(new self(count: $this->count + 1, focused: $this->focused));
        }

        return new UpdateResult($this);
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
    }
}

final readonly class ClickTrackingFocusableApp extends CopyableTestApp
{
    public function __construct(
        public ClickTrackingFocusable $widget,
        public UnchangedFocusable $other,
    ) {
    }

    public function view(): Widget
    {
        return Layout::horizontal(
            TestComponents::bind('click-tracker', 'widget', $this->widget),
            TestComponents::bind('other-widget', 'other', $this->other),
        );
    }

    protected function quitBindings(): Keybindings
    {
        return new Keybindings(['quit' => []]);
    }
}

final readonly class TwoUnchangedFocusableApp extends CopyableTestApp
{
    public function __construct(
        public UnchangedFocusable $left,
        public UnchangedFocusable $right,
    ) {
    }

    public function view(): Widget
    {
        return Layout::horizontal(
            TestComponents::bind('left-widget', 'left', $this->left),
            TestComponents::bind('right-widget', 'right', $this->right),
        );
    }

    protected function quitBindings(): Keybindings
    {
        return new Keybindings(['quit' => []]);
    }
}

final readonly class TabConsumingFocusable implements Focusable
{
    public function __construct(
        public int $tabCount = 0,
        private bool $focused = false,
    ) {
    }

    public function isFocused(): bool
    {
        return $this->focused;
    }

    public function withFocus(bool $focused): static
    {
        return new self(tabCount: $this->tabCount, focused: $focused);
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::flex());
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        if ($message instanceof KeyMsg && $message->key === Key::Tab) {
            return new UpdateResult(new self(tabCount: $this->tabCount + 1, focused: $this->focused));
        }

        return new UpdateResult($this);
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
    }
}

final readonly class TabConsumingApp extends CopyableTestApp
{
    public function __construct(
        public TabConsumingFocusable $widget,
        public UnchangedFocusable $other,
    ) {
    }

    public function view(): Widget
    {
        return Layout::horizontal(
            TestComponents::bind('tab-consumer', 'widget', $this->widget),
            TestComponents::bind('other-widget', 'other', $this->other),
        );
    }

    protected function quitBindings(): Keybindings
    {
        return new Keybindings(['quit' => []]);
    }
}

final readonly class CommandOnClickFocusable implements Focusable
{
    public function __construct(
        private bool $focused = false,
    ) {
    }

    public function isFocused(): bool
    {
        return $this->focused;
    }

    public function withFocus(bool $focused): static
    {
        return new self(focused: $focused);
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::flex());
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        if ($message instanceof MouseMsg && $message->isClick()) {
            return new UpdateResult($this, Cmd::exec(['echo', 'click']));
        }

        return new UpdateResult($this);
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
    }
}

final readonly class MouseCommandFocusableApp extends CopyableTestApp
{
    public function __construct(
        public CommandOnClickFocusable $widget,
    ) {
    }

    public function view(): Widget
    {
        return TestComponents::bind('click-command', 'widget', $this->widget);
    }

    protected function onMouse(MouseMsg $message, Rect $screen): UpdateResult
    {
        if ($message->isClick()) {
            return $this->withCmd(Cmd::tick(0.1));
        }

        return UpdateResult::keep($this);
    }

    protected function quitBindings(): Keybindings
    {
        return new Keybindings(['quit' => []]);
    }
}

final readonly class SingleCommandOnClickApp extends CopyableTestApp
{
    public function __construct(
        public CommandOnClickFocusable $widget,
    ) {
    }

    public function view(): Widget
    {
        return TestComponents::bind('click-command', 'widget', $this->widget);
    }

    protected function quitBindings(): Keybindings
    {
        return new Keybindings(['quit' => []]);
    }
}

final readonly class MouseTrackingFocusableApp extends CopyableTestApp
{
    public function __construct(
        public bool $mouseTracked = false,
        public ClickTrackingFocusable $widget = new ClickTrackingFocusable(),
        public UnchangedFocusable $other = new UnchangedFocusable(),
    ) {
    }

    public function view(): Widget
    {
        return Layout::horizontal(
            TestComponents::bind('click-tracker', 'widget', $this->widget),
            TestComponents::bind('other-widget', 'other', $this->other),
        );
    }

    protected function onMouse(MouseMsg $message, Rect $screen): UpdateResult
    {
        if ($message->isClick()) {
            return UpdateResult::changed($this->cloneWith(mouseTracked: true));
        }

        return UpdateResult::keep($this);
    }

    protected function quitBindings(): Keybindings
    {
        return new Keybindings(['quit' => []]);
    }
}

final readonly class TabCommandOnlyFocusable implements Focusable
{
    public function __construct(
        private bool $focused = false,
    ) {
    }

    public function isFocused(): bool
    {
        return $this->focused;
    }

    public function withFocus(bool $focused): static
    {
        return new self(focused: $focused);
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::flex());
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        if ($message instanceof KeyMsg && $message->key === Key::Tab) {
            return new UpdateResult($this, Cmd::message(new TestMessage('tab-consumed')));
        }

        return new UpdateResult($this);
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
    }
}

final readonly class TabCommandOnlyApp extends CopyableTestApp
{
    public function __construct(
        public TabCommandOnlyFocusable $widget,
        public UnchangedFocusable $other,
    ) {
    }

    public function view(): Widget
    {
        return Layout::horizontal(
            TestComponents::bind('tab-command', 'widget', $this->widget),
            TestComponents::bind('other-widget', 'other', $this->other),
        );
    }

    protected function quitBindings(): Keybindings
    {
        return new Keybindings(['quit' => []]);
    }
}

final readonly class MarkerFocusable implements Focusable
{
    public function __construct(
        public string $marker,
        private bool $focused = false,
    ) {
    }

    public function isFocused(): bool
    {
        return $this->focused;
    }

    public function withFocus(bool $focused): static
    {
        return new self(marker: $this->marker, focused: $focused);
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::flex());
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return new UpdateResult($this);
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
    }
}

final readonly class MouseSwapApp extends CopyableTestApp
{
    public function __construct(
        public MarkerFocusable $left,
        public MarkerFocusable $right,
        public bool $swapped = false,
    ) {
    }

    public static function create(): self
    {
        return new self(
            left: new MarkerFocusable(marker: 'original-left', focused: true),
            right: new MarkerFocusable(marker: 'original-right'),
        );
    }

    public function view(): Widget
    {
        return Layout::horizontal(
            TestComponents::bind('left-marker', 'left', $this->left),
            TestComponents::bind('right-marker', 'right', $this->right),
        );
    }

    protected function onMouse(MouseMsg $message, Rect $screen): UpdateResult
    {
        if ($this->swapped) {
            return UpdateResult::keep($this);
        }

        return UpdateResult::changed($this->cloneWith(
            right: new MarkerFocusable(marker: 'replaced-right'),
            swapped: true,
        ));
    }

    protected function quitBindings(): Keybindings
    {
        return new Keybindings(['quit' => []]);
    }
}
