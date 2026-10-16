<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use ArrayObject;
use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\FocusState;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Layout;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseButton;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\RenderPass;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Focusable;
use Elmphant\Tui\Widget\Structure\Panel;
use Elmphant\Tui\Widget\Widget;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A pane whose state its host still drives can take its turn at the keyboard
 * without handing that state over: joining the tree is about identity, not about
 * who writes the widget back.
 */
final class NamedFocusableTest extends TestCase
{
    #[Test]
    public function aFocusableNamedWithoutAWriteBackCanHoldTheKeyboard(): void
    {
        $frame = new Frame(6, 2);
        $pass = RenderPass::new(Theme::dark());
        $pass->begin(0.0);
        $pass->focus->focusOn(new FocusState(ComponentId::named('pane')));

        (new NamedPaneApp(TextInput::new()))->render($frame, $pass);

        self::assertSame('F', $frame->character(6), 'the named pane is the one the tree focused');
    }

    /**
     * Naming a pane is what says the keyboard may go there. A pane its host draws and
     * drives has no flag of its own to report, and needs none.
     */
    #[Test]
    public function aNamedPaneIsSomewhereTheKeyboardCanGo(): void
    {
        self::assertEquals([ComponentId::named('pane')], (new NamedPlainPaneApp())->focusableIds());
    }

    /**
     * A pane its host draws has no update() to be handed a key, so the host answers
     * while it holds the keyboard.
     */
    #[Test]
    public function theHostAnswersTheKeysWhileAPlainPaneHoldsTheKeyboard(): void
    {
        $seen = new ArrayObject();

        (new NamedPlainPaneKeyApp($seen))->update(
            new KeyMsg('x'),
            new Rect(0, 0, 20, 2),
            new FocusState(ComponentId::named('pane')),
        );

        self::assertSame(['x'], $seen->getArrayCopy());
    }

    #[Test]
    public function aNamedFocusableIsNeverHandedTheKey(): void
    {
        $seen = new ArrayObject();

        $result = (new NamedOnlyApp($seen))->update(
            new KeyMsg('x'),
            new Rect(0, 0, 8, 1),
            new FocusState(ComponentId::named('pane')),
        );

        self::assertSame([], $seen->getArrayCopy(), 'the pane was not asked to answer it');
        self::assertInstanceOf(NamedOnlyApp::class, $result->model);
    }

    #[Test]
    public function aClickOnANamedFocusableIsLeftToItsHost(): void
    {
        $seen = new ArrayObject();

        $result = (new NamedOnlyApp($seen))->update(
            new MouseMsg(0, 0, MouseAction::Press, MouseButton::Left),
            new Rect(0, 0, 8, 1),
        );

        self::assertSame([], $seen->getArrayCopy());
        self::assertInstanceOf(NamedOnlyApp::class, $result->model);
    }

    /**
     * Tab offers the focused component the press first, in case it walks parts of its
     * own. A pane its host draws has nothing to offer it to, so the keyboard moves on.
     */
    #[Test]
    public function tabOnAPlainPaneMovesTheKeyboardOn(): void
    {
        $result = (new PlainPaneBesideAFieldApp(TextInput::new()))->update(
            new KeyMsg(Key::Tab),
            new Rect(0, 0, 20, 2),
            new FocusState(ComponentId::named('pane')),
        );

        self::assertEquals(Cmd::focusNext(), $result->command);
    }

    #[Test]
    public function tabOnANamedPanelMovesTheKeyboardOnInsteadOfStoringItsAnswer(): void
    {
        try {
            $result = (new NamedPanelApp())->update(
                new KeyMsg(Key::Tab),
                new Rect(0, 0, 20, 6),
                new FocusState(ComponentId::named('pane')),
            );
        } catch (LogicException $thrown) {
            self::fail('The press was offered to a pane nothing can write back: ' . $thrown->getMessage());
        }

        self::assertEquals(Cmd::focusNext(), $result->command);
    }
}

final readonly class NamedPaneApp extends App
{
    public function __construct(public TextInput $field)
    {
    }

    public function view(): Widget
    {
        return Layout::vertical(
            Component::bind('field', $this->field, static fn (self $app, TextInput $w): self => $app->cloneWith(field: $w)),
            Component::named('pane', new HostDrivenPane()),
        );
    }
}

final readonly class HostDrivenPane implements Focusable
{
    public function __construct(private bool $focused = false)
    {
    }

    public function isFocused(): bool
    {
        return $this->focused;
    }

    public function withFocus(bool $focused): static
    {
        return new self($focused);
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::exact(1));
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return UpdateResult::keep($this);
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        $frame->putChar($area->x, $area->y, $context->hasFocus() ? 'F' : 'u');
    }
}

final readonly class NamedPlainPaneApp extends App
{
    public function view(): Widget
    {
        return Component::named('pane', new PlainPane());
    }
}

final readonly class NamedPlainPaneKeyApp extends App
{
    public function __construct(private ArrayObject $seen)
    {
    }

    public function view(): Widget
    {
        return Component::named('pane', new PlainPane());
    }

    protected function onKey(KeyMsg $message, Rect $screen): UpdateResult
    {
        $this->seen[] = $message->key instanceof Key ? $message->key->value : $message->key;

        return UpdateResult::keep($this);
    }
}

final readonly class PlainPaneBesideAFieldApp extends App
{
    public function __construct(public TextInput $field)
    {
    }

    public function view(): Widget
    {
        return Layout::vertical(
            Component::named('pane', new PlainPane()),
            Component::bind('field', $this->field, static fn (self $app, TextInput $w): self => $app->cloneWith(field: $w)),
        );
    }
}

final readonly class NamedPanelApp extends App
{
    public function view(): Widget
    {
        return Component::named(
            'pane',
            Panel::new('Pane')->withChildren([
                TextInput::new(),
                TextInput::new(),
            ]),
        );
    }
}
