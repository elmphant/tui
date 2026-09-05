<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use Elmphant\Tui\Core\App;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Component;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Layout;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\ResizeMsg;
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Testing\VirtualTerminal;
use Elmphant\Tui\Widget\Dispatch\ScrollPane;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Structure\Panel;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProgramFocusTest extends TestCase
{
    #[Test]
    public function aFocusCommandDecidesWhichFieldTheTypingReaches(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $terminal->simulateInput('x');
        $terminal->simulateInput('q');

        $final = Program::new(new FocusCommandApp())->withTerminal($terminal)->start();

        self::assertInstanceOf(FocusCommandApp::class, $final);
        self::assertSame('', $final->first->value());
        self::assertSame('x', $final->second->value());
    }

    /**
     * An app that never places the keyboard still has one somewhere to type.
     */
    #[Test]
    public function theKeyboardStartsOnTheFirstComponentWithoutBeingAsked(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $terminal->simulateInput('x');
        $terminal->simulateInput('q');

        $final = Program::new(new FocusCyclingApp())->withTerminal($terminal)->start();

        self::assertInstanceOf(FocusCyclingApp::class, $final);
        self::assertSame('x', $final->first->value());
        self::assertSame('', $final->second->value());
    }

    /**
     * The announcement is unshifted ahead of whatever was already being handled,
     * so that message has to come back afterwards rather than be dropped or run
     * twice. At startup the message in hand is the terminal's own size.
     */
    #[Test]
    public function theMessageWaitingOnTheFirstPlacementIsHandledExactlyOnce(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $terminal->simulateInput('q');

        $final = Program::new(new ResizeCountingApp())->withTerminal($terminal)->start();

        self::assertInstanceOf(ResizeCountingApp::class, $final);
        self::assertSame(1, $final->resizes);
    }

    #[Test]
    public function thePlacementPutsTheKeyboardOnAPanesFirstField(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $terminal->simulateInput('x');
        $terminal->simulateInput('q');

        $final = Program::new(SeededPaneApp::twoFields())->withTerminal($terminal)->start();

        self::assertInstanceOf(SeededPaneApp::class, $final);
        self::assertSame(['x', ''], $final->fieldValues());
    }

    #[Test]
    public function theFirstKeystrokeReachesTheFieldTheRuntimePlacedTheKeyboardOn(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $terminal->simulateInput('a');
        $terminal->simulateInput('q');

        $final = Program::new(PaneCyclingApp::seeded())->withTerminal($terminal)->start();

        self::assertInstanceOf(PaneCyclingApp::class, $final);
        self::assertSame(['a', ''], $final->valuesOfLeft());
    }

    #[Test]
    public function aPaneTheKeyboardComesBackToStartsAtItsFirstChildAgain(): void
    {
        $terminal = new VirtualTerminal(80, 24);

        foreach (['a', "\t", 'b', "\t", 'c', "\t", 'd', 'q'] as $keystroke) {
            $terminal->simulateInput($keystroke);
        }

        $final = Program::new(PaneCyclingApp::seeded())->withTerminal($terminal)->start();

        self::assertInstanceOf(PaneCyclingApp::class, $final);
        self::assertSame(['ad', 'b'], $final->valuesOfLeft());
    }

    #[Test]
    public function focusNextWalksTheComponentsTheViewPublishes(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $terminal->simulateInput('n');
        $terminal->simulateInput('x');
        $terminal->simulateInput('q');

        $final = Program::new(new FocusCyclingApp())->withTerminal($terminal)->start();

        self::assertInstanceOf(FocusCyclingApp::class, $final);
        self::assertSame('', $final->first->value());
        self::assertSame('x', $final->second->value());
    }

    #[Test]
    public function steppingBackFromTheFirstComponentWrapsToTheLast(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $terminal->simulateInput('p');
        $terminal->simulateInput('x');
        $terminal->simulateInput('q');

        $final = Program::new(new FocusCyclingApp())->withTerminal($terminal)->start();

        self::assertInstanceOf(FocusCyclingApp::class, $final);
        self::assertSame('', $final->first->value());
        self::assertSame('x', $final->second->value());
    }

    #[Test]
    public function tabStepsOnFromWhereTheRuntimePutTheKeyboard(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $terminal->simulateInput("\t");
        $terminal->simulateInput('x');
        $terminal->simulateInput('q');

        $final = Program::new(new TabFromAPlacedFocusApp())->withTerminal($terminal)->start();

        self::assertInstanceOf(TabFromAPlacedFocusApp::class, $final);
        self::assertSame('', $final->first->value());
        self::assertSame('', $final->second->value());
        self::assertSame('x', $final->third->value());
    }
}

final readonly class TabFromAPlacedFocusApp extends App
{
    public function __construct(
        public TextInput $first = new TextInput(),
        public TextInput $second = new TextInput(),
        public TextInput $third = new TextInput(),
    ) {
    }

    public function view(): Widget
    {
        return Layout::vertical(
            Component::bind('first', $this->first, static fn (self $app, TextInput $field): self => $app->cloneWith(first: $field)),
            Component::bind('second', $this->second, static fn (self $app, TextInput $field): self => $app->cloneWith(second: $field)),
            Component::bind('third', $this->third, static fn (self $app, TextInput $field): self => $app->cloneWith(third: $field)),
        );
    }

    protected function setup(): ?Cmd
    {
        return Cmd::focus(ComponentId::named('second'));
    }

    protected function onKey(KeyMsg $message, Rect $screen): UpdateResult
    {
        return 'q' === $message->key ? $this->withCmd(Cmd::quit()) : UpdateResult::keep($this);
    }
}

final readonly class SeededPaneApp extends App
{
    public function __construct(public ScrollPane $pane = new ScrollPane())
    {
    }

    public static function twoFields(): self
    {
        return new self(ScrollPane::of(TextInput::new(), TextInput::new()));
    }

    public function view(): Widget
    {
        return Component::bind('pane', $this->pane, static fn (self $app, ScrollPane $pane): self => $app->cloneWith(pane: $pane));
    }

    /**
     * @return list<string>
     */
    public function fieldValues(): array
    {
        $values = [];

        foreach ($this->pane->authoredChildren() as $child) {
            if ($child instanceof TextInput) {
                $values[] = $child->value();
            }
        }

        return $values;
    }

    protected function onKey(KeyMsg $message, Rect $screen): UpdateResult
    {
        return 'q' === $message->key ? $this->withCmd(Cmd::quit()) : UpdateResult::keep($this);
    }
}

final readonly class ResizeCountingApp extends App
{
    public function __construct(
        public TextInput $field = new TextInput(),
        public int $resizes = 0,
    ) {
    }

    public function view(): Widget
    {
        return Component::bind('field', $this->field, static fn (self $app, TextInput $field): self => $app->cloneWith(field: $field));
    }

    protected function onResize(ResizeMsg $message): UpdateResult
    {
        return UpdateResult::keep($this->cloneWith(resizes: $this->resizes + 1));
    }

    protected function onKey(KeyMsg $message, Rect $screen): UpdateResult
    {
        return 'q' === $message->key ? $this->withCmd(Cmd::quit()) : UpdateResult::keep($this);
    }
}

final readonly class PaneCyclingApp extends App
{
    public function __construct(
        public Panel $left = new Panel('L'),
        public Panel $right = new Panel('R'),
    ) {
    }

    public static function seeded(): self
    {
        return new self(
            Panel::new('L')->add(TextInput::new())->add(TextInput::new()),
            Panel::new('R')->add(TextInput::new()),
        );
    }

    public function view(): Widget
    {
        return Layout::vertical(
            Component::bind('left', $this->left, static fn (self $app, Panel $pane): self => $app->cloneWith(left: $pane)),
            Component::bind('right', $this->right, static fn (self $app, Panel $pane): self => $app->cloneWith(right: $pane)),
        );
    }

    /**
     * @return list<string>
     */
    public function valuesOfLeft(): array
    {
        $values = [];

        foreach ($this->left->children() as $child) {
            if ($child instanceof TextInput) {
                $values[] = $child->value();
            }
        }

        return $values;
    }

    protected function onKey(KeyMsg $message, Rect $screen): UpdateResult
    {
        return 'q' === $message->key ? $this->withCmd(Cmd::quit()) : UpdateResult::keep($this);
    }
}

final readonly class FocusCyclingApp extends App
{
    public function __construct(
        public TextInput $first = new TextInput(),
        public TextInput $second = new TextInput(),
    ) {
    }

    public function view(): Widget
    {
        return Layout::vertical(
            Component::bind('first', $this->first, static fn (self $app, TextInput $field): self => $app->cloneWith(first: $field)),
            Component::bind('second', $this->second, static fn (self $app, TextInput $field): self => $app->cloneWith(second: $field)),
        );
    }

    protected function onKey(KeyMsg $message, Rect $screen): UpdateResult
    {
        return match ($message->key) {
            'n' => $this->withCmd(Cmd::focusNext()),
            'p' => $this->withCmd(Cmd::focusPrevious()),
            'q' => $this->withCmd(Cmd::quit()),
            default => UpdateResult::keep($this),
        };
    }
}

final readonly class FocusCommandApp extends App
{
    public function __construct(
        public TextInput $first = new TextInput(),
        public TextInput $second = new TextInput(),
    ) {
    }

    public function view(): Widget
    {
        return Layout::vertical(
            Component::bind('first', $this->first, static fn (self $app, TextInput $field): self => $app->cloneWith(first: $field)),
            Component::bind('second', $this->second, static fn (self $app, TextInput $field): self => $app->cloneWith(second: $field)),
        );
    }

    protected function setup(): ?Cmd
    {
        return Cmd::focus(ComponentId::named('second'));
    }

    protected function onKey(KeyMsg $message, Rect $screen): UpdateResult
    {
        return 'q' === $message->key ? $this->withCmd(Cmd::quit()) : UpdateResult::keep($this);
    }
}
