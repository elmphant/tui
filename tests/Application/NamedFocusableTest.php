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
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseButton;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Context\RenderPass;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Focusable;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Structure\Panel;
use Elmphant\Tui\Widget\Widget;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Named panes participate in tree focus while their host retains state and input handling.
 */
final class NamedFocusableTest extends TestCase
{
    #[Test]
    public function aFocusableNamedWithoutAWriteBackCanHoldTheKeyboard(): void
    {
        $frame = new Frame(6, 2);
        $pass = RenderPass::new(Theme::dark());
        $pass->begin(0.0);
        $pass->focus->focusOn(FocusState::on(ComponentId::named('pane')));

        (new NamedPaneApp(TextInput::new()))->render($frame, $pass);

        self::assertSame('F', $frame->character(6), 'the named pane is the one the tree focused');
    }

    #[Test]
    public function aNamedPaneIsSomewhereTheKeyboardCanGo(): void
    {
        self::assertEquals([ComponentId::named('pane')], (new NamedPlainPaneApp())->ringIds());
    }

    #[Test]
    public function theHostAnswersTheKeysWhileAPlainPaneHoldsTheKeyboard(): void
    {
        $seen = new ArrayObject();

        (new NamedPlainPaneKeyApp($seen))->update(
            new KeyMsg('x'),
            new Rect(0, 0, 20, 2),
            FocusState::on(ComponentId::named('pane')),
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
            FocusState::on(ComponentId::named('pane')),
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
     * Named host-managed panes have no widget update() to consume Tab, so focus must move on.
     */
    #[Test]
    public function tabOnAPlainPaneMovesTheKeyboardOn(): void
    {
        $result = (new PlainPaneBesideAFieldApp(TextInput::new()))->update(
            new KeyMsg(Key::Tab),
            new Rect(0, 0, 20, 2),
            FocusState::on(ComponentId::named('pane')),
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
                FocusState::on(ComponentId::named('pane')),
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
        return UpdateResult::ignored($this);
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

        return UpdateResult::ignored($this);
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
