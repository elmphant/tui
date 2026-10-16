<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Core;

use Elmphant\Tui\Core\App;
use Elmphant\Tui\Core\Component;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Focusable;
use Elmphant\Tui\Widget\Structure\Panel;
use Elmphant\Tui\Widget\Widget;
use Elmphant\Tui\Widget\WidgetContainer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A stop that publishes no children of its own holds the keyboard for everything
 * it draws, so a pane it builds inside itself shows the reader where they are.
 */
final class LooseKeyboardTest extends TestCase
{
    #[Test]
    public function aStopThatBuildsItsOwnPaneLightsTheFieldInside(): void
    {
        $harness = ModelHarness::for(new InlinePaneApp(new InlinePane(TextInput::new())), 40, 8);

        self::assertSame('pane', $harness->focused()?->value);
        self::assertNotNull(
            $harness->renderFrame()->cursorPosition,
            'the field inside is the only thing the keyboard can be on',
        );
    }
}

final readonly class InlinePaneApp extends App
{
    public function __construct(public InlinePane $pane)
    {
    }

    public function view(): Widget
    {
        return Component::bind('pane', $this->pane, static fn (self $app, InlinePane $pane): self => $app->cloneWith(pane: $pane));
    }
}

/**
 * Shaped like a host's own pane: one stop that draws a Panel of its own rather
 * than publishing what it holds.
 */
final readonly class InlinePane implements Focusable, WidgetContainer
{
    public function __construct(private TextInput $field, private bool $focused = false)
    {
    }

    public function isFocused(): bool
    {
        return $this->focused;
    }

    public function withFocus(bool $focused): static
    {
        return new self($this->field, $focused);
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::flex());
    }

    /**
     * @return list<Widget>
     */
    public function children(): iterable
    {
        return [$this->field];
    }

    /**
     * @return list<array{Widget, Rect}>
     */
    public function placedChildren(Rect $area): iterable
    {
        return [[$this->field, $area]];
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return new UpdateResult($this);
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        Panel::new('Form')->withChildren([$this->field])->render($frame, $area, $context);
    }
}
