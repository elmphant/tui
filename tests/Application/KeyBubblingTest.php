<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Support\Cloneable;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Interactive;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Widget;
use Elmphant\Tui\Widget\WidgetContainer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A pane that scrolls holds its own children, and once one of them takes the
 * keyboard the pane is no longer where keys arrive. A key the child does not
 * answer is the pane's, or nothing could scroll a form while a field is being
 * filled in.
 */
final class KeyBubblingTest extends TestCase
{
    #[Test]
    public function theEnclosingComponentAnswersWhatTheFocusedOneIgnored(): void
    {
        $driven = ModelHarness::for(new FieldInsideAPaneApp(new ScrollingPane(TextInput::new())), 20, 4)->initialize();

        $driven->dispatch(new KeyMsg(Key::Down));

        self::assertSame(1, $driven->model()->pane->scrolled, 'the pane it sits in took the key');
    }

    #[Test]
    public function aKeyTheFocusedComponentAnswersNeverLeavesIt(): void
    {
        $driven = ModelHarness::for(new FieldInsideAPaneApp(new ScrollingPane(TextInput::new())), 20, 4)->initialize();

        $driven->dispatch(new KeyMsg('x'));

        self::assertSame('x', $driven->model()->pane->field->value(), 'the field kept it');
        self::assertSame(0, $driven->model()->pane->scrolled, 'so the pane never saw it');
    }

    #[Test]
    public function aComponentThatDoesNotEncloseTheFocusedOneIsLeftAlone(): void
    {
        $driven = ModelHarness::for(new FieldInsideAPaneApp(new ScrollingPane(TextInput::new())), 20, 4)->initialize();

        $driven->dispatch(new KeyMsg(Key::Down));

        self::assertSame(1, $driven->model()->pane->scrolled, 'the pane around the field took it');
        self::assertSame(
            0,
            $driven->model()->bystander->seen,
            'a key goes outwards from where it was, never sideways to whatever else is bound',
        );
    }
}

/**
 * Interactive but not Focusable: it writes back, so the keyboard never stops on
 * it, which is exactly the case a scrolling pane of fields is.
 */
final readonly class ScrollingPane implements Interactive, WidgetContainer
{
    use Cloneable;

    public function __construct(
        public TextInput $field,
        public int $scrolled = 0,
    ) {
    }

    public function sizing(): Sizing
    {
        return $this->inner()->sizing();
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return $message instanceof KeyMsg && $message->key === Key::Down
            ? new UpdateResult($this->cloneWith(scrolled: $this->scrolled + 1))
            : UpdateResult::ignored($this);
    }

    public function withField(TextInput $field): self
    {
        return $this->cloneWith(field: $field);
    }

    /**
     * @return iterable<Widget>
     */
    public function children(): iterable
    {
        return [$this->inner()];
    }

    /**
     * @return iterable<array{Widget, Rect}>
     */
    public function placedChildren(Rect $area): iterable
    {
        return [[$this->inner(), $area]];
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        $this->inner()->render($frame, $area, $context);
    }

    private function inner(): Widget
    {
        return Component::bind(
            'field',
            $this->field,
            static fn (FieldInsideAPaneApp $app, TextInput $field): FieldInsideAPaneApp => $app->withPane($app->pane->withField($field)),
        );
    }
}

/**
 * Beside the pane rather than around it, so a key it ever answers is a key that
 * went somewhere it had no business going.
 */
final readonly class Bystander implements Interactive
{
    use Cloneable;

    public function __construct(public int $seen = 0)
    {
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::exact(1));
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return new UpdateResult($this->cloneWith(seen: $this->seen + 1));
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
    }
}

final readonly class FieldInsideAPaneApp extends App
{
    use Cloneable;

    public function __construct(
        public ScrollingPane $pane,
        public Bystander $bystander = new Bystander(),
    ) {
    }

    public function withPane(ScrollingPane $pane): self
    {
        return $this->cloneWith(pane: $pane);
    }

    public function view(): Widget
    {
        return Layout::vertical(
            Component::bind(
                'pane',
                $this->pane,
                static fn (self $app, ScrollingPane $pane): self => $app->cloneWith(pane: $pane),
            ),
            Component::bind(
                'bystander',
                $this->bystander,
                static fn (self $app, Bystander $seen): self => $app->cloneWith(bystander: $seen),
            ),
        );
    }
}
