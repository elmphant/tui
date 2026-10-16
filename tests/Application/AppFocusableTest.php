<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Cmd\BatchCmd;
use Elmphant\Tui\Core\Cmd\ExecCmd;
use Elmphant\Tui\Core\Cmd\FocusCmd;
use Elmphant\Tui\Core\Cmd\QuitCmd;
use Elmphant\Tui\Core\Cmd\TickCmd;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\FocusState;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Input\KeybindingsAware;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseButton;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Message\TickMsg;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Tests\Application\Support\CopyableTestApp;
use Elmphant\Tui\Tests\Support\TestMessage;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Focusable;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Structure\Box;
use Elmphant\Tui\Widget\Structure\Panel;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\DataProvider;
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
    public function aKeyReachesNothingWhenTheFocusedComponentIsClippedAway(): void
    {
        $app = new ClippedFocusApp(
            visible: new CountingFocusable(),
            middle: new CountingFocusable(),
            clipped: (new CountingFocusable())->withFocus(true),
        );

        $result = $app->update(new KeyMsg('a'), Rect::fromSize(8, 4));

        self::assertSame(0, $result->model->clipped->count);
    }

    /**
     * A clipped component has no rect to dispatch into, so the tab passes it by —
     * and must pass it by in the direction it was pressed.
     */
    #[Test]
    #[DataProvider('tabDirections')]
    public function tabMovesOnInItsOwnDirectionWhenTheFocusedComponentIsClippedAway(KeyMsg $key, string $lands): void
    {
        $app = new ClippedFocusApp(
            visible: new CountingFocusable(),
            middle: new CountingFocusable(),
            clipped: new CountingFocusable(),
        );

        $result = $app->update($key, Rect::fromSize(8, 4), self::keyboardOn('clipped-last'));

        self::assertEquals(
            ComponentId::named($lands),
            self::keyboardLandsOn($result->command, $app->focusableIds(), 'clipped-last'),
        );
    }

    /**
     * @return iterable<string, array{KeyMsg, string}>
     */
    public static function tabDirections(): iterable
    {
        yield 'tab wraps on past the last' => [new KeyMsg(Key::Tab), 'clipped-visible'];
        yield 'shift+tab steps back' => [new KeyMsg(Key::Tab, shift: true), 'clipped-middle'];
    }

    /**
     * The onMouse hook may rebuild the tree, so the click has to be delivered
     * against the components the changed model publishes, not the ones read
     * before the hook ran.
     */
    #[Test]
    public function aClickReachesTheTreeTheMouseHookLeftBehind(): void
    {
        $app = new SwappingOnMouseApp(
            before: TextInput::new()->withValue('aaaa'),
            after: TextInput::new()->withValue('bbbb'),
        );

        $result = $app->update(new MouseMsg(1, 0, MouseAction::Press, MouseButton::Left), Rect::fromSize(20, 1));

        self::assertInstanceOf(SwappingOnMouseApp::class, $result->model);
        self::assertSame(1, $result->model->after->cursor(), 'the widget the hook swapped in took the click');
        self::assertSame(4, $result->model->before->cursor(), 'the one it swapped out was left alone');
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

        $result = $app->update(new KeyMsg('q'), Rect::fromSize(80, 24), self::keyboardOn('left-panel'));
        self::assertNotInstanceOf(QuitCmd::class, $result->command);
    }

    #[Test]
    public function theQuitBindingStillQuitsWhileNoFocusableHoldsFocus(): void
    {
        $app = new TwoPanelApp(
            left: Panel::new('Left'),
            right: Panel::new('Right'),
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

    /**
     * A broadcast walks every component, and one it has to skip — nothing writes
     * this one back — must not stop the walk before the rest.
     */
    #[Test]
    public function aBroadcastCarriesPastAComponentNothingWritesBack(): void
    {
        $app = new SkippedThenReachedApp();

        $result = $app->update(new MouseMsg(1, 2, MouseAction::Press, MouseButton::Left), Rect::fromSize(20, 4));

        self::assertInstanceOf(SkippedThenReachedApp::class, $result->model);
        self::assertSame(1, $result->model->reached->count);
    }

    /**
     * With nothing to cycle between, tab is not the framework's to take: an app
     * without components may bind it to whatever it likes.
     */
    #[Test]
    public function anAppWithNothingFocusableStillSeesTabInItsOwnHook(): void
    {
        $app = new SimpleCounterApp();

        $result = $app->update(new KeyMsg(Key::Tab), Rect::fromSize(80, 24));

        self::assertInstanceOf(SimpleCounterApp::class, $result->model);
        self::assertSame(1, $result->model->count);
        self::assertNull($result->command, 'nothing asked the runtime to move a keyboard that has nowhere to go');
    }

    /**
     * A pane publishes its children, so tab walks them without leaving it.
     */
    #[Test]
    public function aPanesOwnChildrenAreStopsOfTheirOwn(): void
    {
        $outerLeft = Panel::new('Outer')->add(Panel::new('Inner1'))->add(Panel::new('Inner2'));

        $app = new TwoPanelApp(left: $outerLeft, right: Panel::new('Right'));
        $result = $app->update(new KeyMsg(Key::Tab), Rect::fromSize(80, 24), self::keyboardOn('left-panel/0'));

        self::assertEquals(
            ComponentId::named('left-panel/1'),
            self::keyboardLandsOn($result->command, $app->focusableIds(), 'left-panel/0'),
        );
    }

    #[Test]
    public function keyDelegatesToFocusedAndReflectsWidgetChange(): void
    {
        $app = new CountingFocusableApp(
            counter: new CountingFocusable(focused: true),
        );

        $result = $app->update(new KeyMsg('x'), Rect::fromSize(80, 24), self::keyboardOn('counting-widget'));
        self::assertInstanceOf(CountingFocusableApp::class, $result->model);
        self::assertSame(1, $result->model->counter->count);
    }

    #[Test]
    public function keyDelegatedToFocusedReturnsCommandFromWidget(): void
    {
        $app = new CountingFocusableApp(
            counter: new CountingFocusable(focused: true),
        );

        $result = $app->update(new KeyMsg('c'), Rect::fromSize(80, 24), self::keyboardOn('counting-widget'));
        self::assertInstanceOf(ExecCmd::class, $result->command);
    }

    #[Test]
    public function keyToUnfocusedAppReturnsUnchanged(): void
    {
        $app = new TwoPanelApp(
            left: Panel::new('Left'),
            right: Panel::new('Right'),
        );

        $result = $app->update(new KeyMsg('x'), Rect::fromSize(80, 24));
        self::assertSame($app, $result->model);
        self::assertNull($result->command);
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

        $result = $app->update(
            new MouseMsg(5, 5, MouseAction::Press, MouseButton::Left),
            Rect::fromSize(80, 24),
        );

        self::assertInstanceOf(TwoPanelApp::class, $result->model);
        self::assertEquals(Cmd::focus(ComponentId::named('left-panel')), $result->command);
    }

    #[Test]
    public function findFocusedKeyReturnsFocusedWidgetKey(): void
    {
        $app = new CountingFocusableApp(
            counter: new CountingFocusable(focused: true),
        );

        $result = $app->update(new KeyMsg('x'), Rect::fromSize(80, 24), self::keyboardOn('counting-widget'));
        self::assertSame(1, $result->model->counter->count);
    }

    #[Test]
    public function findFocusedKeySkipsUnfocusedWidgets(): void
    {
        $app = new TwoCountingFocusableApp(
            left: new CountingFocusable(count: 0, focused: false),
            right: new CountingFocusable(count: 0, focused: true),
        );

        $result = $app->update(new KeyMsg('x'), Rect::fromSize(80, 24), self::keyboardOn('right-counter'));
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
            left: Panel::new('Left'),
            right: Panel::new('Right'),
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

        $result = $app->update(new KeyMsg('x'), Rect::fromSize(80, 24), self::keyboardOn('counting-widget'));
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

        $result = $app->update(new KeyMsg('x'), Rect::fromSize(80, 24), self::keyboardOn('rect-capture'));
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

        $result = $app->update(new KeyMsg('x'), Rect::fromSize(80, 24), self::keyboardOn('right-capture'));
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

        $result = $app->update(new KeyMsg(Key::Tab), Rect::fromSize(80, 24), self::keyboardOn('tab-consumer'));

        self::assertInstanceOf(TabConsumingApp::class, $result->model);
        self::assertSame(1, $result->model->widget->tabCount);
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
    }

    #[Test]
    public function onKeyReturningNoOpUpdateResultStillDelegatesToFocused(): void
    {
        $app = new NoOpUpdateResultApp(
            counter: new CountingFocusable(focused: true),
        );

        $result = $app->update(new KeyMsg('x'), Rect::fromSize(80, 24), self::keyboardOn('counting-widget'));

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
     * Driving update() by hand means saying where the keyboard is, as the runtime does.
     */
    private static function keyboardOn(string $id): FocusState
    {
        return new FocusState(ComponentId::named($id));
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

/**
 * The click lands behind a component nothing writes back.
 */
final readonly class SkippedThenReachedApp extends CopyableTestApp
{
    public function __construct(
        public ClickTrackingFocusable $reached = new ClickTrackingFocusable(),
    ) {
    }

    public function view(): Widget
    {
        return Box::new('Rows')
            ->add(Component::named('read-only', Text::new('chrome')))
            ->add($this->bindProperty('reached', $this->reached, 'reached'));
    }
}

final readonly class SwappingOnMouseApp extends CopyableTestApp
{
    public function __construct(
        public TextInput $before,
        public TextInput $after,
        public bool $swapped = false,
    ) {
    }

    public function view(): Widget
    {
        return $this->swapped
            ? $this->bindProperty('after', $this->after, 'field')
            : $this->bindProperty('before', $this->before, 'field');
    }

    protected function onMouse(MouseMsg $message, Rect $screen): UpdateResult
    {
        return UpdateResult::ignored($this->cloneWith(swapped: true));
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
            left: Panel::new('Left'),
            right: Panel::new('Right'),
        );
    }

    public function view(): Widget
    {
        return Layout::horizontal(
            $this->bindProperty('left', $this->left, 'left-panel'),
            $this->bindProperty('right', $this->right, 'right-panel'),
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
            left: Panel::new('Left'),
            right: Panel::new('Right'),
        );
    }

    public function view(): Widget
    {
        return Layout::horizontal(
            $this->bindProperty('left', $this->left, 'left-panel'),
            $this->bindProperty('right', $this->right, 'right-panel'),
        );
    }

    protected function onKey(KeyMsg $message, Rect $screen): UpdateResult
    {
        return match ($message->key) {
            ' ' => new UpdateResult($this->cloneWith(toggled: !$this->toggled)),
            default => UpdateResult::ignored($this),
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
        return $this->bindProperty('widget', $this->widget, 'shortcut-widget');
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
        return new UpdateResult($this->cloneWith(lastAction: $action));
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
        return $this->bindProperty('widget', $this->widget, 'unhandled-shortcut-widget');
    }

    protected function applicationKeybindings(): Keybindings
    {
        return new Keybindings(['help' => ['f1']]);
    }
}

final readonly class ClippedFocusApp extends CopyableTestApp
{
    public function __construct(
        public CountingFocusable $visible,
        public CountingFocusable $middle,
        public CountingFocusable $clipped,
    ) {
    }

    public function view(): Widget
    {
        return Box::new('Clipped')
            ->add($this->bindProperty('visible', $this->visible, 'clipped-visible'))
            ->add($this->bindProperty('middle', $this->middle, 'clipped-middle'))
            ->add($this->bindProperty('clipped', $this->clipped, 'clipped-last'));
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
            'k', Key::Tab => new UpdateResult($this->cloneWith(count: $this->count + 1)),
            default => UpdateResult::ignored($this),
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
            $this->bindProperty('first', $this->first, 'first-panel'),
            $this->bindProperty('second', $this->second, 'second-panel'),
            $this->bindProperty('third', $this->third, 'third-panel'),
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
        return new self(panel: Panel::new('Detached'));
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
        private readonly bool $focused = false,
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
        return $this->bindProperty('widget', $this->widget, 'binding-capture');
    }

    protected function onMouse(MouseMsg $message, Rect $screen): UpdateResult
    {
        return new UpdateResult($this->cloneWith(currentBindings: $this->nextBindings));
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
        return $this->bindProperty('widget', $this->widget, 'binding-capture');
    }

    protected function onTick(TickMsg $message, Rect $screen): UpdateResult
    {
        return new UpdateResult($this->cloneWith(currentBindings: $this->nextBindings));
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
        return $this->bindProperty('counter', $this->counter, 'counting-widget');
    }

    protected function onKey(KeyMsg $message, Rect $screen): UpdateResult
    {
        return UpdateResult::ignored($this);
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
        return $this->bindProperty('counter', $this->counter, 'counting-widget');
    }

    protected function onKey(KeyMsg $message, Rect $screen): UpdateResult
    {
        return new UpdateResult($this->cloneWith(toggled: true));
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
        return $this->bindProperty('counter', $this->counter, 'counting-widget');
    }

    protected function onKey(KeyMsg $message, Rect $screen): UpdateResult
    {
        return new UpdateResult($this, Cmd::quit());
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
        return $this->bindProperty('counter', $this->counter, 'counting-widget');
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
        return $this->bindProperty('widget', $this->widget, 'unchanged-widget');
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
        return $this->bindProperty('widget', $this->widget, 'rect-capture');
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
            $this->bindProperty('left', $this->left, 'left-capture'),
            $this->bindProperty('right', $this->right, 'right-capture'),
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
            $this->bindProperty('left', $this->left, 'left-counter'),
            $this->bindProperty('right', $this->right, 'right-counter'),
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
            $this->bindProperty('widget', $this->widget, 'click-tracker'),
            $this->bindProperty('other', $this->other, 'other-widget'),
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
            $this->bindProperty('left', $this->left, 'left-widget'),
            $this->bindProperty('right', $this->right, 'right-widget'),
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
            $this->bindProperty('widget', $this->widget, 'tab-consumer'),
            $this->bindProperty('other', $this->other, 'other-widget'),
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
        return $this->bindProperty('widget', $this->widget, 'click-command');
    }

    protected function onMouse(MouseMsg $message, Rect $screen): UpdateResult
    {
        if ($message->isClick()) {
            return $this->then(Cmd::tick(0.1));
        }

        return UpdateResult::ignored($this);
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
        return $this->bindProperty('widget', $this->widget, 'click-command');
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
            $this->bindProperty('widget', $this->widget, 'click-tracker'),
            $this->bindProperty('other', $this->other, 'other-widget'),
        );
    }

    protected function onMouse(MouseMsg $message, Rect $screen): UpdateResult
    {
        if ($message->isClick()) {
            return new UpdateResult($this->cloneWith(mouseTracked: true));
        }

        return UpdateResult::ignored($this);
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
            $this->bindProperty('widget', $this->widget, 'tab-command'),
            $this->bindProperty('other', $this->other, 'other-widget'),
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
            $this->bindProperty('left', $this->left, 'left-marker'),
            $this->bindProperty('right', $this->right, 'right-marker'),
        );
    }

    protected function onMouse(MouseMsg $message, Rect $screen): UpdateResult
    {
        if ($this->swapped) {
            return UpdateResult::ignored($this);
        }

        return new UpdateResult($this->cloneWith(
            right: new MarkerFocusable(marker: 'replaced-right'),
            swapped: true,
        ));
    }

    protected function quitBindings(): Keybindings
    {
        return new Keybindings(['quit' => []]);
    }
}
