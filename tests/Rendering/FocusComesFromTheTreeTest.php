<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Rendering;

use Elmphant\Tui\Core\App;
use Elmphant\Tui\Core\Component;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\FocusState;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Layout;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Focusable;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FocusComesFromTheTreeTest extends TestCase
{
    #[Test]
    public function aFlaggedWidgetShowsNoRingUntilSomethingAboveItSaysSo(): void
    {
        $frame = new Frame(6, 1);

        (new FlaggedProbe(true))->render($frame, Rect::fromSize(6, 1), new RenderContext(Theme::dark()));

        self::assertSame('u', $frame->character(0), 'nothing said where the keyboard is, so nothing shows it');
    }

    #[Test]
    public function aContextDeclaredFocusedStaysFocusedOnTheNextFrame(): void
    {
        $frame = new Frame(6, 1);
        $context = RenderContext::focused(Theme::dark());
        $context->beginFrame(0.0);

        (new FlaggedProbe())->render($frame, Rect::fromSize(6, 1), $context);

        self::assertSame('F', $frame->character(0));
    }

    /**
     * An app that routes the keys itself says where they went by opening a scope
     * around the widget it fed, the way Component does for a bound one.
     */
    #[Test]
    public function aWidgetNobodyBoundShowsNoRingOfItsOwn(): void
    {
        $frame = new Frame(6, 2);
        $context = new RenderContext(Theme::dark());
        $context->beginFrame(0.0);
        $context->focusOn(new FocusState(ComponentId::named('field')));

        (new MixedTreeApp())->render($frame, $context);

        self::assertSame('u', $frame->character(6), 'the unbound pane was never given the keyboard');
    }

    /**
     * Nothing tells the pane that lost the keyboard, so its flag stays true one
     * message longer than the truth.
     */
    #[Test]
    public function aBoundPaneStillFlaggedDoesNotDrawAsFocusedOnceTheKeyboardHasMoved(): void
    {
        $frame = new Frame(6, 2);
        $context = new RenderContext(Theme::dark());
        $context->beginFrame(0.0);
        $context->focusOn(new FocusState(ComponentId::named('right')));

        (new TwoProbeApp())->render($frame, $context);

        self::assertSame('u', $frame->character(0), 'the pane that kept a stale flag');
        self::assertSame('F', $frame->character(6), 'the pane the keyboard is actually on');
    }
}

final readonly class TwoProbeApp extends App
{
    public function view(): Widget
    {
        return Layout::vertical(
            Component::bind('left', new FlaggedProbe(true), static fn (self $app, FlaggedProbe $w): self => $app),
            Component::bind('right', new FlaggedProbe(false), static fn (self $app, FlaggedProbe $w): self => $app),
        );
    }
}

final readonly class MixedTreeApp extends App
{
    public function __construct(public TextInput $field = new TextInput())
    {
    }

    public function view(): Widget
    {
        return Layout::vertical(
            Component::bind('field', $this->field, static fn (self $app, TextInput $w): self => $app->cloneWith(field: $w)),
            new FocusProbe(),
        );
    }
}

/**
 * Draws what the context answers about its own flag, so a frame says which pane
 * a reader would see as holding the keyboard.
 */
final readonly class FlaggedProbe implements Focusable
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

    public function update(Message $message, Rect $area): UpdateResult
    {
        return new UpdateResult($this);
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::exact(1));
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        $frame->putChar($area->x, $area->y, $context->hasFocus() ? 'F' : 'u');
    }
}

final readonly class FocusProbe implements Widget
{
    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::exact(1));
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        $frame->putChar($area->x, $area->y, $context->hasFocus() ? 'F' : 'u');
    }
}
