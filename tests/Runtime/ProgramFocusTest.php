<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use ArrayObject;
use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\FocusState;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\FocusMsg;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\ResizeMsg;
use Elmphant\Tui\Rendering\Context\RenderPass;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Runtime\RoutesFocus;
use Elmphant\Tui\Testing\VirtualTerminal;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Structure\Panel;
use Elmphant\Tui\Widget\Structure\ScrollPane;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;

final class ProgramFocusTest extends TestCase
{
    #[Test]
    public function aFocusCommandDecidesWhichFieldTheTypingReaches(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $terminal->simulateInput('x');
        $terminal->simulateInput('q');

        $final = Program::new(new FocusCommandApp(TextInput::new(), TextInput::new()))->withTerminal($terminal)->start();

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

        $final = Program::new(new FocusCyclingApp(TextInput::new(), TextInput::new()))->withTerminal($terminal)->start();

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

        $final = Program::new(new ResizeCountingApp(TextInput::new()))->withTerminal($terminal)->start();

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

        $final = Program::new(new FocusCyclingApp(TextInput::new(), TextInput::new()))->withTerminal($terminal)->start();

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

        $final = Program::new(new FocusCyclingApp(TextInput::new(), TextInput::new()))->withTerminal($terminal)->start();

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

        $final = Program::new(new TabFromAPlacedFocusApp(TextInput::new(), TextInput::new(), TextInput::new()))->withTerminal($terminal)->start();

        self::assertInstanceOf(TabFromAPlacedFocusApp::class, $final);
        self::assertSame('', $final->first->value());
        self::assertSame('', $final->second->value());
        self::assertSame('x', $final->third->value());
    }

    #[Test]
    public function theModelHearsTheKeyboardArriveBeforeItsFirstMessage(): void
    {
        $log = new ArrayObject();
        $terminal = new VirtualTerminal(20, 5);
        $terminal->simulateInput('q');

        Program::new(new FocusLoggingModel($log))->withTerminal($terminal)->start();

        self::assertSame(['focus:a', 'resize', 'key:q'], $log->getArrayCopy());
    }

    #[Test]
    public function aFocusMoveRedrawsAndIsAnnouncedThoughTheModelIsUnchanged(): void
    {
        $log = new ArrayObject();
        $terminal = new VirtualTerminal(20, 5);
        EventLoop::delay(0.02, static fn () => $terminal->simulateInput('n'));
        EventLoop::delay(0.08, static fn () => $terminal->simulateInput('q'));

        Program::new(new FocusLoggingModel($log))->withTerminal($terminal)->start();

        self::assertSame(['focus:a', 'resize', 'render', 'key:n', 'focus:b', 'render', 'key:q'], $log->getArrayCopy());
    }

    #[Test]
    public function aComponentLeavingFromUnderTheKeyboardIsAnnouncedOnceTheFrameIsDrawn(): void
    {
        $log = new ArrayObject();
        $terminal = new VirtualTerminal(20, 5);
        EventLoop::delay(0.02, static fn () => $terminal->simulateInput('x'));
        EventLoop::delay(0.08, static fn () => $terminal->simulateInput('q'));

        Program::new(new FocusLoggingModel($log))->withTerminal($terminal)->start();

        self::assertSame(['focus:a', 'resize', 'render', 'key:x', 'render', 'focus:b', 'key:q'], $log->getArrayCopy());
    }
}

/**
 * Records what reaches it: x takes the first component away, n moves the keyboard on.
 */
final readonly class FocusLoggingModel implements RoutesFocus
{
    /**
     * @param ArrayObject<int, string> $log
     * @param list<string>             $ids
     */
    public function __construct(private ArrayObject $log, private array $ids = ['a', 'b'])
    {
    }

    public function init(): ?Cmd
    {
        return null;
    }

    public function update(Message $message, Rect $area, FocusState $focus = new FocusState()): UpdateResult
    {
        if ($message instanceof FocusMsg) {
            $this->log->append('focus:' . $focus->focused()?->value);
        }
        if ($message instanceof ResizeMsg) {
            $this->log->append('resize');
        }
        if (!$message instanceof KeyMsg) {
            return new UpdateResult($this);
        }
        $this->log->append('key:' . $message->key);

        return match ($message->key) {
            'x' => new UpdateResult(new self($this->log, array_slice($this->ids, 1))),
            'n' => new UpdateResult($this, Cmd::focusNext()),
            'q' => new UpdateResult($this, Cmd::quit()),
            default => new UpdateResult($this),
        };
    }

    public function focusableIds(FocusState $focus = new FocusState()): array
    {
        return array_map(ComponentId::named(...), $this->ids);
    }

    public function reachableIds(): array
    {
        return $this->focusableIds();
    }

    public function render(Frame $frame, RenderPass $pass): void
    {
        $this->log->append('render');
    }
}

final readonly class TabFromAPlacedFocusApp extends App
{
    public function __construct(
        public TextInput $first,
        public TextInput $second,
        public TextInput $third,
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
        return 'q' === $message->key ? $this->then(Cmd::quit()) : UpdateResult::ignored($this);
    }
}

final readonly class SeededPaneApp extends App
{
    public function __construct(public ScrollPane $pane)
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
        return 'q' === $message->key ? $this->then(Cmd::quit()) : UpdateResult::ignored($this);
    }
}

final readonly class ResizeCountingApp extends App
{
    public function __construct(
        public TextInput $field,
        public int $resizes = 0,
    ) {
    }

    public function view(): Widget
    {
        return Component::bind('field', $this->field, static fn (self $app, TextInput $field): self => $app->cloneWith(field: $field));
    }

    protected function onResize(ResizeMsg $message): UpdateResult
    {
        return UpdateResult::ignored($this->cloneWith(resizes: $this->resizes + 1));
    }

    protected function onKey(KeyMsg $message, Rect $screen): UpdateResult
    {
        return 'q' === $message->key ? $this->then(Cmd::quit()) : UpdateResult::ignored($this);
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
        return 'q' === $message->key ? $this->then(Cmd::quit()) : UpdateResult::ignored($this);
    }
}

final readonly class FocusCyclingApp extends App
{
    public function __construct(
        public TextInput $first,
        public TextInput $second,
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
            'n' => $this->then(Cmd::focusNext()),
            'p' => $this->then(Cmd::focusPrevious()),
            'q' => $this->then(Cmd::quit()),
            default => UpdateResult::ignored($this),
        };
    }
}

final readonly class FocusCommandApp extends App
{
    public function __construct(
        public TextInput $first,
        public TextInput $second,
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
        return 'q' === $message->key ? $this->then(Cmd::quit()) : UpdateResult::ignored($this);
    }
}
