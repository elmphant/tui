<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Core;

use Elmphant\Tui\Core\App;
use Elmphant\Tui\Core\Cloneable;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Cmd\FocusCmd;
use Elmphant\Tui\Core\Component;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\FocusState;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Layout;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseButton;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Dispatch\ScrollPane;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Focusable;
use Elmphant\Tui\Widget\Prose\Spacer;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A pointer moves the keyboard the same way a key does, so it asks the runtime rather
 * than editing a flag — a click and a key have to agree on who holds it.
 */
final class PointerFocusTest extends TestCase
{
    #[Test]
    public function aClickOnAnotherComponentAsksForTheKeyboardToMoveThere(): void
    {
        $clicked = (new TwoFieldPointerApp())->update(
            new MouseMsg(1, 0, MouseAction::Press, MouseButton::Left),
            new Rect(0, 0, 40, 2),
            new FocusState(ComponentId::named('second')),
        );

        self::assertInstanceOf(FocusCmd::class, $clicked->command);
        self::assertEquals(Cmd::focus(ComponentId::named('first')), $clicked->command);
    }

    /**
     * Wheeling through a form is reading, not choosing a field. The keyboard
     * follows the wheel only to something that scrolled with it.
     */
    #[Test]
    public function aWheelOverAFieldThatIgnoresItLeavesTheKeyboardWhereItWas(): void
    {
        $turned = (new TwoFieldPointerApp())->update(
            new MouseMsg(1, 0, MouseAction::WheelDown, MouseButton::None),
            new Rect(0, 0, 40, 2),
            new FocusState(ComponentId::named('second')),
        );

        self::assertNull($turned->command);
    }

    #[Test]
    public function aWheelOverAPaneThatScrollsWithItTakesTheKeyboardThere(): void
    {
        $turned = FieldAboveScrollingPaneApp::seeded()->update(
            new MouseMsg(1, 2, MouseAction::WheelDown, MouseButton::None),
            new Rect(0, 0, 20, 4),
            new FocusState(ComponentId::named('field')),
        );

        self::assertEquals(Cmd::focus(ComponentId::named('pane')), $turned->command);
    }

    #[Test]
    public function aWheelOverNothingLeavesTheKeyboardWhereItWas(): void
    {
        $turned = (new TwoFieldPointerApp())->update(
            new MouseMsg(1, 30, MouseAction::WheelDown, MouseButton::None),
            new Rect(0, 0, 40, 2),
            new FocusState(ComponentId::named('second')),
        );

        self::assertNull($turned->command, 'a click on empty space means "not here"; a wheel there means nothing');
    }

    /**
     * Which panes the keyboard may reach is the tree's answer, not a question about
     * what each widget implements. A pane its host draws is one of them.
     */
    #[Test]
    public function aClickOnAPlainPaneAsksForTheKeyboard(): void
    {
        $clicked = (new FieldAbovePlainPaneApp())->update(
            new MouseMsg(0, 1, MouseAction::Press, MouseButton::Left),
            new Rect(0, 0, 20, 2),
        );

        self::assertEquals(Cmd::focus(ComponentId::named('pane')), $clicked->command);
    }

    #[Test]
    public function aClickFindsTheChildUnderItEvenWhenThePaneIsScrolled(): void
    {
        $clicked = ScrolledPaneApp::scrolled()->update(
            new MouseMsg(0, 0, MouseAction::Press, MouseButton::Left),
            new Rect(0, 0, 20, 2),
        );

        self::assertEquals(
            Cmd::focus(ComponentId::named('pane/1')),
            $clicked->command,
            'the first row shows the second field once the pane is scrolled',
        );
    }

    #[Test]
    public function anAppThatMovesTheKeyboardOnAPressIsNotOverruledByTheDefault(): void
    {
        $driven = ModelHarness::for(new ClickableChromeApp(), 40, 3)->initialize();

        $driven->dispatch(new MouseMsg(0, 0, MouseAction::Press, MouseButton::Left));

        self::assertTrue(
            $driven->focused()?->equals(ComponentId::named('second')),
            'the app answered the press and named where the keys go, so the keys have to be there',
        );
    }
}

final readonly class ScrolledPaneApp extends App
{
    public function __construct(public ScrollPane $pane = new ScrollPane())
    {
    }

    public static function scrolled(): self
    {
        return new self(
            ScrollPane::of(TextInput::new(), TextInput::new(), TextInput::new())->withScrollOffset(1),
        );
    }

    public function view(): Widget
    {
        return Component::bind('pane', $this->pane, static fn (self $app, ScrollPane $pane): self => $app->cloneWith(pane: $pane));
    }
}

final readonly class TwoFieldPointerApp extends App
{
    public function __construct(
        public TextInput $first = new TextInput(),
        public TextInput $second = new TextInput(),
    ) {
    }

    public function view(): Widget
    {
        return Layout::vertical(
            Component::bind('first', $this->first, static fn (self $app, TextInput $w): self => $app->cloneWith(first: $w)),
            Component::bind('second', $this->second, static fn (self $app, TextInput $w): self => $app->cloneWith(second: $w)),
        );
    }
}

final readonly class FieldAboveScrollingPaneApp extends App
{
    public function __construct(
        public TextInput $field = new TextInput(),
        public ScrollPane $pane = new ScrollPane(),
    ) {
    }

    public static function seeded(): self
    {
        return new self(pane: ScrollPane::of(...array_map(
            static fn (int $row): Text => Text::new('row' . $row),
            range(1, 6),
        )));
    }

    public function view(): Widget
    {
        return Layout::vertical(
            Component::bind('field', $this->field, static fn (self $app, TextInput $field): self => $app->cloneWith(field: $field)),
            Component::bind('pane', $this->pane, static fn (self $app, ScrollPane $pane): self => $app->cloneWith(pane: $pane)),
        );
    }
}

final readonly class FieldAbovePlainPaneApp extends App
{
    public function __construct(public TextInput $field = new TextInput())
    {
    }

    public function view(): Widget
    {
        return Layout::vertical(
            Component::bind('field', $this->field, static fn (self $app, TextInput $w): self => $app->cloneWith(field: $w)),
            Component::named('pane', new PlainPane()),
        );
    }
}

final readonly class ClickableChromeApp extends App
{
    public function __construct(
        public TextInput $first = new TextInput(),
        public TextInput $second = new TextInput(),
    ) {
    }

    public function view(): Widget
    {
        return Layout::vertical(
            Spacer::new(),
            Component::bind('first', $this->first, static fn (self $app, TextInput $field): self => $app->cloneWith(first: $field)),
            Component::bind('second', $this->second, static fn (self $app, TextInput $field): self => $app->cloneWith(second: $field)),
        );
    }

    protected function onMouse(MouseMsg $message, Rect $screen): UpdateResult
    {
        return $message->y === 0
            ? $this->withCmd(Cmd::focus(ComponentId::named('second')))
            : UpdateResult::keep($this);
    }
}

final readonly class WatchingApp extends App
{
    use Cloneable;

    public function __construct(
        public KeyboardWatcher $watcher = new KeyboardWatcher(),
        public KeyboardWatcher $bystander = new KeyboardWatcher(),
    ) {
    }

    public function view(): Widget
    {
        return Layout::vertical(
            Component::bind(
                'watcher',
                $this->watcher,
                static fn (self $app, KeyboardWatcher $w): self => $app->cloneWith(watcher: $w),
            ),
            Component::bind(
                'bystander',
                $this->bystander,
                static fn (self $app, KeyboardWatcher $w): self => $app->cloneWith(bystander: $w),
            ),
        );
    }
}

/**
 * Records what it was told about the keyboard the last time it was pressed.
 */
final readonly class KeyboardWatcher implements Focusable
{
    use Cloneable;

    public function __construct(
        private bool $focused = false,
        private ?bool $sawTheKeyboard = null,
    ) {
    }

    public function sawTheKeyboard(): ?bool
    {
        return $this->sawTheKeyboard;
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
        return Sizing::of(Constraint::flex(), Constraint::exact(1));
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        if ($message instanceof MouseMsg && $message->isClick()) {
            return UpdateResult::changed($this->cloneWith(sawTheKeyboard: $this->focused));
        }

        return UpdateResult::keep($this);
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
    }
}
