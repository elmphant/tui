<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Application\Keyboard;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Cmd\ExecCmd;
use Elmphant\Tui\Core\Cmd\QuitCmd;
use Elmphant\Tui\Core\Cmd\TickCmd;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\FocusState;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Message\KeyEventType;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseButton;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Message\ResizeMsg;
use Elmphant\Tui\Message\TickMsg;
use Elmphant\Tui\Rendering\Context\FloatMouseMsg;
use Elmphant\Tui\Rendering\Context\FloatPlacement;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Context\RenderPass;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Runtime\Model;
use Elmphant\Tui\Tests\Application\Support\CopyableTestApp;
use Elmphant\Tui\Tests\Support\TestMessage;
use Elmphant\Tui\Tests\Support\TestPopup;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Focusable;
use Elmphant\Tui\Widget\Interactive;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class AppTest extends TestCase
{
    private RenderPass $pass;

    #[Test]
    public function implementsModel(): void
    {
        $app = new CounterApp();
        self::assertInstanceOf(Model::class, $app);
    }

    #[Test]
    public function initDelegatesToSetupReturnsNull(): void
    {
        $app = new CounterApp();
        self::assertNull($app->init());
    }

    #[Test]
    public function initDelegatesToSetupReturnsCmd(): void
    {
        $app = new TickingApp();
        $command = $app->init();
        self::assertInstanceOf(TickCmd::class, $command);
        self::assertSame(0.05, $command->interval);
    }

    #[Test]
    public function theQuitHintNamesTheKeyThatActuallyLeaves(): void
    {
        self::assertSame(
            ['Ctrl+Q' => 'quit'],
            (new CounterApp())->quitHints(),
            'a footer asks rather than repeating a key that can drift out of step',
        );
    }

    #[Test]
    public function aPlainLetterReachesTheAppRatherThanLeavingIt(): void
    {
        $app = new CounterApp();

        $result = $app->update(new KeyMsg('q'), Rect::fromSize(80, 24));

        self::assertNull($result->command, 'nothing printable ends the program unless an app asks for it');
    }

    #[Test]
    public function quitOnCtrlC(): void
    {
        $app = new CounterApp();
        $result = $app->update(new KeyMsg('c', ctrl: true), Rect::fromSize(80, 24));
        self::assertInstanceOf(QuitCmd::class, $result->command);
    }

    #[Test]
    public function ctrlCCanBeReboundViaApplicationKeybindings(): void
    {
        $app = new RebindQuitApp();

        $ctrlC = $app->update(new KeyMsg('c', ctrl: true), Rect::fromSize(80, 24));
        self::assertNull($ctrlC->command, 'An app that rebinds quit must be able to intercept ctrl+c');

        $ctrlD = $app->update(new KeyMsg('d', ctrl: true), Rect::fromSize(80, 24));
        self::assertInstanceOf(QuitCmd::class, $ctrlD->command);
    }

    #[Test]
    public function onComponentsChangedLetsTheAppDeriveStateFromChildUpdates(): void
    {
        $app = SearchApp::create();

        $result = $app->update(new KeyMsg('a'), Rect::fromSize(80, 24), FocusState::on(ComponentId::named('search-input')));

        self::assertInstanceOf(SearchApp::class, $result->model);
        self::assertSame('a', $result->model->query->value(), 'The focused input received the key');
        self::assertSame('a', $result->model->lastSeenQuery, 'The hook derived state from the changed component');
        self::assertSame(['search-input'], $result->model->changedIds, 'The hook receives the changed component ids');
    }

    #[Test]
    public function onComponentsChangedIsNotCalledWhenNoComponentChanged(): void
    {
        $app = SearchApp::create();

        $result = $app->update(new TestMessage('noop'), Rect::fromSize(80, 24));

        self::assertInstanceOf(SearchApp::class, $result->model);
        self::assertSame('', $result->model->lastSeenQuery);
        self::assertSame([], $result->model->changedIds);
    }

    #[Test]
    public function onComponentsChangedIsNotCalledWhenAMouseEventChangesNoComponent(): void
    {
        $app = new NoOpMouseApp(input: TextInput::new()->withPlaceholder('x'));

        $result = $app->update(
            new MouseMsg(0, 0, MouseAction::WheelDown, MouseButton::None),
            Rect::fromSize(80, 24),
        );

        self::assertNull(
            $result->command,
            'the hook signals with a quit, and an ignored wheel never reaches it; nor does the keyboard follow a wheel nothing answered',
        );
    }

    #[Test]
    public function applicationKeybindingsIsResolvedOncePerAppInstance(): void
    {
        KeybindingsCallCounter::$calls = 0;
        $app = new KeybindingsCountingApp();

        $app->update(new KeyMsg('x'), Rect::fromSize(80, 24));
        $app->update(new KeyMsg('y'), Rect::fromSize(80, 24));

        self::assertSame(1, KeybindingsCallCounter::$calls, 'applicationKeybindings() must be memoized per app instance');
    }

    #[Test]
    public function onResizeHookReceivesResizeMsg(): void
    {
        $app = new ResizeAwareApp();

        $result = $app->update(new ResizeMsg(120, 40), Rect::fromSize(120, 40));

        self::assertInstanceOf(ResizeAwareApp::class, $result->model);
        self::assertSame(120, $result->model->lastWidth);
        self::assertSame(40, $result->model->lastHeight);
    }

    #[Test]
    public function onKeyReceivesKeyMsgAndChangesState(): void
    {
        $app = new CounterApp();
        $result = $app->update(new KeyMsg('k'), Rect::fromSize(80, 24));
        self::assertInstanceOf(CounterApp::class, $result->model);
        self::assertSame(1, $result->model->count);

        $result = $result->model->update(new KeyMsg('j'), Rect::fromSize(80, 24));
        self::assertSame(0, $result->model->count);
    }

    #[Test]
    public function onKeyCanReturnUpdateResultWithCmd(): void
    {
        $app = new CmdApp();
        $result = $app->update(new KeyMsg('r'), Rect::fromSize(80, 24));
        self::assertInstanceOf(CmdApp::class, $result->model);
        self::assertTrue($result->model->requested);
        self::assertInstanceOf(ExecCmd::class, $result->command);
    }

    #[Test]
    public function onMouseReceivesMouseMsg(): void
    {
        $app = new MouseApp();
        self::assertFalse($app->clicked);

        $result = $app->update(new MouseMsg(5, 5, MouseAction::Press, MouseButton::Left), Rect::fromSize(80, 24));
        self::assertInstanceOf(MouseApp::class, $result->model);
        self::assertTrue($result->model->clicked);
    }

    #[Test]
    public function onMouseReceivesScreenRect(): void
    {
        $app = new MouseRectApp();
        $screenRect = new Rect(0, 0, 120, 40);

        $result = $app->update(new MouseMsg(5, 5, MouseAction::Press, MouseButton::Left), $screenRect);

        self::assertInstanceOf(MouseRectApp::class, $result->model);
        self::assertSame(120, $result->model->width);
        self::assertSame(40, $result->model->height);
    }

    #[Test]
    public function onMouseReceivesScreenRectWithFocusables(): void
    {
        $app = new MouseRectWithFocusablesApp(0, 0, TextInput::new());
        $screenRect = new Rect(0, 0, 77, 31);

        $result = $app->update(new MouseMsg(5, 5, MouseAction::Press, MouseButton::Left), $screenRect);

        self::assertInstanceOf(MouseRectWithFocusablesApp::class, $result->model);
        self::assertSame(77, $result->model->width);
        self::assertSame(31, $result->model->height);
    }

    #[Test]
    public function onTickReceivesTickMsg(): void
    {
        $app = new TickingApp();
        self::assertSame(0, $app->ticks);

        $result = $app->update(new TickMsg(microtime(true)), Rect::fromSize(80, 24));
        self::assertInstanceOf(TickingApp::class, $result->model);
        self::assertSame(1, $result->model->ticks);
    }

    #[Test]
    public function aFloatMouseMsgReachesTheOnMessageHookWhenNoComponentsAreBound(): void
    {
        $app = new FloatHookApp();
        $message = self::floatMouse();

        $result = $app->update($message, Rect::fromSize(80, 24));

        self::assertInstanceOf(FloatHookApp::class, $result->model);
        self::assertSame($message, $result->model->received);
    }

    #[Test]
    public function aFloatMouseMsgReachesAnUnfocusedBoundComponent(): void
    {
        $app = new FloatBroadcastApp(new FloatRecordingWidget());
        $message = self::floatMouse();

        $result = $app->update($message, Rect::fromSize(80, 24));

        self::assertInstanceOf(FloatBroadcastApp::class, $result->model);
        self::assertSame($message, $result->model->received, 'the hook sees it first');
        self::assertSame($message, $result->model->widget->receivedFloat, 'and it is broadcast, not focus-delegated');
    }

    #[Test]
    public function onMessageReceivesCustomMessages(): void
    {
        $app = new CounterApp();
        $result = $app->update(new TestMessage('some-custom-message'), Rect::fromSize(80, 24));
        self::assertInstanceOf(CounterApp::class, $result->model);
        self::assertSame(0, $result->model->count);
        self::assertNull($result->command);
    }

    #[Test]
    public function resizeMsgHandledSilently(): void
    {
        $app = new CounterApp(count: 5);
        $result = $app->update(new ResizeMsg(120, 40), Rect::fromSize(80, 24));
        self::assertInstanceOf(CounterApp::class, $result->model);
        self::assertSame(5, $result->model->count);
        self::assertNull($result->command);
    }

    #[Test]
    public function renderRendersViewWidget(): void
    {
        $app = new CounterApp(count: 42);
        $frame = new Frame(80, 24);
        $app->render($frame, $this->pass);

        $rendered = '';
        for ($column = 0; $column < 80; ++$column) {
            $character = $frame->character($column);
            if ($character === ' ') {
                break;
            }
            $rendered .= $character;
        }
        self::assertSame('Count:', $rendered);
    }

    #[Test]
    public function modelUpdateUsesTheCanonicalRectAwareContract(): void
    {
        $app = new CounterApp();
        $result = $app->update(new KeyMsg('k'), Rect::fromSize(80, 24));
        self::assertInstanceOf(CounterApp::class, $result->model);
        self::assertSame(1, $result->model->count);
    }

    #[Test]
    public function modelRenderUsesTheFrameDimensionsForTheView(): void
    {
        $app = new CounterApp(count: 7);
        $frame = new Frame(80, 24);
        $app->render($frame, $this->pass);

        $rendered = '';
        for ($column = 0; $column < 80; ++$column) {
            $character = $frame->character($column);
            if ($character === ' ') {
                break;
            }
            $rendered .= $character;
        }
        self::assertSame('Count:', $rendered);
    }

    #[Test]
    public function withCmdReturnsUpdateResult(): void
    {
        $app = new CmdApp();
        $result = $app->update(new KeyMsg('r'), Rect::fromSize(80, 24));
        self::assertInstanceOf(CmdApp::class, $result->model);
        self::assertNotNull($result->command);
    }

    #[Test]
    public function updatePassesTheSuppliedScreenRect(): void
    {
        $widget = new RectCapturingWidget();
        $app = new RectCapturingApp(widget: $widget);
        $result = $app->update(new TickMsg(1.0), Rect::fromSize(80, 24));

        self::assertInstanceOf(RectCapturingApp::class, $result->model);
        self::assertSame(80, $result->model->widget->capturedWidth);
        self::assertSame(24, $result->model->widget->capturedHeight);
    }

    #[Test]
    public function resizeMsgReturnsEarlyBeforeOnMessage(): void
    {
        $app = new ResizeTrackingApp();
        $result = $app->update(new ResizeMsg(120, 40), Rect::fromSize(120, 40));

        self::assertSame($app, $result->model);
        self::assertFalse($result->model->messageReceived);
    }

    #[Test]
    public function aLetterAddedToTheQuitActionQuits(): void
    {
        $app = new CustomQuitXApp();

        $result = $app->update(new KeyMsg('x'), Rect::fromSize(80, 24));

        self::assertInstanceOf(QuitCmd::class, $result->command);
    }

    #[Test]
    public function ctrlQQuitsByDefault(): void
    {
        $app = new CounterApp();

        $result = $app->update(new KeyMsg('q', ctrl: true), Rect::fromSize(80, 24));

        self::assertInstanceOf(QuitCmd::class, $result->command);
    }

    #[Test]
    public function quitBindingIgnoresReleaseEvents(): void
    {
        $app = new CounterApp();

        $result = $app->update(
            new KeyMsg('q', eventType: KeyEventType::Release),
            Rect::fromSize(80, 24),
        );

        self::assertNull($result->command);
    }

    #[Test]
    public function aKeyBoundToAnotherActionDoesNotQuit(): void
    {
        $app = new NotQuitActionApp();

        $result = $app->update(new KeyMsg('q'), Rect::fromSize(80, 24));

        self::assertNull($result->command);
    }

    #[Test]
    public function appHooksHaveOneUpdateResultShapeWithoutWrappingIndirection(): void
    {
        self::assertFalse(method_exists(App::class, 'wrapResult'));

        $app = new CounterApp();

        $result = $app->update(new KeyMsg('a'), Rect::fromSize(80, 24));

        self::assertSame($app, $result->model);
    }

    #[Test]
    public function appExtensionHooksRemainProtectedSubclassContracts(): void
    {
        foreach (['setup', 'onKey', 'onAction', 'onMouse', 'onTick', 'onMessage', 'onResize', 'onComponentsChanged', 'applicationKeybindings'] as $hook) {
            self::assertTrue(
                (new ReflectionMethod(App::class, $hook))->isProtected(),
                "$hook must remain overridable by App subclasses",
            );
        }
    }

    protected function setUp(): void
    {
        $this->pass = RenderPass::new(Theme::dark());
    }

    private static function floatMouse(): FloatMouseMsg
    {
        $placement = new Rect(5, 5, 10, 4);

        return new FloatMouseMsg(
            new MouseMsg(6, 6, MouseAction::Press, MouseButton::Left),
            new FloatPlacement(new TestPopup($placement), $placement, $placement),
        );
    }
}

final readonly class CounterApp extends CopyableTestApp
{
    public function __construct(public int $count = 0)
    {
    }

    public function view(): Widget
    {
        return Text::new("Count: {$this->count}");
    }

    protected function onKey(KeyMsg $message, Rect $screen): UpdateResult
    {
        return match ($message->key) {
            'k' => new UpdateResult($this->cloneWith(count: $this->count + 1)),
            'j' => new UpdateResult($this->cloneWith(count: $this->count - 1)),
            default => UpdateResult::ignored($this),
        };
    }
}

final readonly class SearchApp extends CopyableTestApp
{
    /**
     * @param list<string> $changedIds
     */
    public function __construct(
        public TextInput $query,
        public string $lastSeenQuery = '',
        public array $changedIds = [],
    ) {
    }

    public static function create(): self
    {
        return new self(query: TextInput::new()->withPlaceholder('search'));
    }

    public function view(): Widget
    {
        return Component::bind(
            'search-input',
            $this->query,
            static fn (self $app, TextInput $input): self => $app->cloneWith(query: $input),
        );
    }

    protected function onComponentsChanged(array $ids): UpdateResult
    {
        return new UpdateResult($this->cloneWith(
            lastSeenQuery: $this->query->value(),
            changedIds: array_map(static fn (ComponentId $id): string => $id->value, $ids),
        ));
    }
}

final readonly class RebindQuitApp extends CopyableTestApp
{
    public function __construct()
    {
    }

    public function view(): Widget
    {
        return Text::new('rebound quit');
    }

    protected function applicationKeybindings(): Keybindings
    {
        return new Keybindings(['quit' => ['ctrl+d']]);
    }
}

final readonly class ResizeAwareApp extends CopyableTestApp
{
    public function __construct(
        public int $lastWidth = 0,
        public int $lastHeight = 0,
    ) {
    }

    public function view(): Widget
    {
        return Text::new('resize aware');
    }

    protected function onResize(ResizeMsg $message): UpdateResult
    {
        return new UpdateResult($this->cloneWith(lastWidth: $message->width, lastHeight: $message->height));
    }
}

final readonly class CustomQuitXApp extends CopyableTestApp
{
    public function __construct()
    {
    }

    public function view(): Widget
    {
        return Text::new('x quits');
    }

    protected function applicationKeybindings(): Keybindings
    {
        return new Keybindings([Actions::QUIT => ['ctrl+q', 'ctrl+c', 'x']]);
    }
}

final readonly class NotQuitActionApp extends CopyableTestApp
{
    public function __construct()
    {
    }

    public function view(): Widget
    {
        return Text::new('q does not quit');
    }

    protected function applicationKeybindings(): Keybindings
    {
        return parent::applicationKeybindings()->overriddenBy(new Keybindings(['not_quit' => ['q']]));
    }
}

final readonly class TickingApp extends CopyableTestApp
{
    public function __construct(public int $ticks = 0)
    {
    }

    public function view(): Widget
    {
        return Text::new("Ticks: {$this->ticks}");
    }

    protected function setup(): ?Cmd
    {
        return Cmd::tick(0.05);
    }

    protected function onTick(TickMsg $message, Rect $screen): UpdateResult
    {
        return new UpdateResult($this->cloneWith(ticks: $this->ticks + 1));
    }
}

final readonly class MouseApp extends CopyableTestApp
{
    public function __construct(public bool $clicked = false)
    {
    }

    public function view(): Widget
    {
        return Text::new($this->clicked ? 'clicked' : 'waiting');
    }

    protected function onMouse(MouseMsg $message, Rect $screen, Keyboard $keyboard): UpdateResult
    {
        return $message->isClick()
            ? new UpdateResult($this->cloneWith(clicked: true))
            : UpdateResult::ignored($this);
    }
}

final readonly class MouseRectApp extends CopyableTestApp
{
    public function __construct(public int $width = 0, public int $height = 0)
    {
    }

    public function view(): Widget
    {
        return Text::new('x');
    }

    protected function onMouse(MouseMsg $message, Rect $screen, Keyboard $keyboard): UpdateResult
    {
        return new UpdateResult($this->cloneWith(width: $screen->width, height: $screen->height));
    }
}

final readonly class MouseRectWithFocusablesApp extends CopyableTestApp
{
    public function __construct(
        public int $width,
        public int $height,
        public TextInput $input,
    ) {
    }

    public function view(): Widget
    {
        return Component::bind(
            ComponentId::named('main-input'),
            $this->input,
            static fn (self $app, TextInput $input): self => $app->cloneWith(input: $input),
        );
    }

    protected function onMouse(MouseMsg $message, Rect $screen, Keyboard $keyboard): UpdateResult
    {
        return new UpdateResult($this->cloneWith(width: $screen->width, height: $screen->height));
    }
}

final readonly class CmdApp extends CopyableTestApp
{
    public function __construct(public bool $requested = false)
    {
    }

    public function view(): Widget
    {
        return Text::new('cmd');
    }

    protected function onKey(KeyMsg $message, Rect $screen): UpdateResult
    {
        return match ($message->key) {
            'r' => $this->cloneWith(requested: true)->then(Cmd::exec(['echo', 'hi'])),
            default => UpdateResult::ignored($this),
        };
    }
}

final readonly class ResizeTrackingApp extends CopyableTestApp
{
    public function __construct(public bool $messageReceived = false)
    {
    }

    public function view(): Widget
    {
        return Text::new('resize tracking');
    }

    protected function onMessage(Message $message, Rect $screen): UpdateResult
    {
        return new UpdateResult($this->cloneWith(messageReceived: true));
    }
}

final readonly class FloatHookApp extends CopyableTestApp
{
    public function __construct(public ?FloatMouseMsg $received = null)
    {
    }

    public function view(): Widget
    {
        return Text::new('shell');
    }

    protected function onMessage(Message $message, Rect $screen): UpdateResult
    {
        return $message instanceof FloatMouseMsg
            ? new UpdateResult($this->cloneWith(received: $message))
            : UpdateResult::ignored($this);
    }
}

final readonly class FloatRecordingWidget implements Focusable
{
    public function __construct(
        public ?FloatMouseMsg $receivedFloat = null,
        public int $plainMouseSeen = 0,
        private bool $focused = false,
    ) {
    }

    public function isFocused(): bool
    {
        return $this->focused;
    }

    public function withFocus(bool $focused): static
    {
        return new self($this->receivedFloat, $this->plainMouseSeen, $focused);
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::exact(1), Constraint::exact(1));
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        if ($message instanceof FloatMouseMsg) {
            return new UpdateResult(new self($message, $this->plainMouseSeen, $this->focused));
        }

        if ($message instanceof MouseMsg) {
            return new UpdateResult(new self($this->receivedFloat, $this->plainMouseSeen + 1, $this->focused));
        }

        return UpdateResult::ignored($this);
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
    }
}

final readonly class FloatBroadcastApp extends CopyableTestApp
{
    public function __construct(
        public FloatRecordingWidget $widget,
        public ?FloatMouseMsg $received = null,
    ) {
    }

    public function view(): Widget
    {
        return Component::bind(
            ComponentId::named('float-recording-widget'),
            $this->widget,
            static fn (self $app, FloatRecordingWidget $widget): self => $app->cloneWith(widget: $widget),
        );
    }

    protected function onMessage(Message $message, Rect $screen): UpdateResult
    {
        return $message instanceof FloatMouseMsg
            ? new UpdateResult($this->cloneWith(received: $message))
            : UpdateResult::ignored($this);
    }
}

final readonly class RectCapturingWidget implements Interactive
{
    public function __construct(
        public int $capturedWidth = 0,
        public int $capturedHeight = 0,
    ) {
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::flex());
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        if ($message instanceof TickMsg) {
            return new UpdateResult(new self(capturedWidth: $area->width, capturedHeight: $area->height));
        }

        return new UpdateResult($this);
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
    }
}

final readonly class RectCapturingApp extends CopyableTestApp
{
    public function __construct(
        public RectCapturingWidget $widget,
    ) {
    }

    public function view(): Widget
    {
        return Component::bind(
            ComponentId::named('rect-capturing-widget'),
            $this->widget,
            static fn (self $app, RectCapturingWidget $widget): self => $app->cloneWith(widget: $widget),
        );
    }
}

final readonly class NoOpMouseApp extends CopyableTestApp
{
    public function __construct(
        public TextInput $input,
    ) {
    }

    public function view(): Widget
    {
        return Component::bind(
            ComponentId::named('no-op-input'),
            $this->input,
            static fn (self $app, TextInput $input): self => $app->cloneWith(input: $input),
        );
    }

    protected function onComponentsChanged(array $ids): UpdateResult
    {
        return new UpdateResult($this, Cmd::quit());
    }
}

final class KeybindingsCallCounter
{
    public static int $calls = 0;
}

final readonly class KeybindingsCountingApp extends CopyableTestApp
{
    public function view(): Widget
    {
        return Text::new('counting');
    }

    protected function applicationKeybindings(): Keybindings
    {
        ++KeybindingsCallCounter::$calls;

        return new Keybindings(['quit' => ['ctrl+q']]);
    }
}
