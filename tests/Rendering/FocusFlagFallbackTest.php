<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Rendering;

use Elmphant\Tui\Core\App;
use Elmphant\Tui\Core\Component;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\FocusState;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Layout;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FocusFlagFallbackTest extends TestCase
{
    /**
     * A widget nobody bound has only its own flag to go on. Silencing it because
     * some component elsewhere holds the keyboard leaves a pane that reads the
     * keys with no way to say so.
     */
    #[Test]
    public function aFlaggedWidgetTheTreeDoesNotKnowKeepsItsRing(): void
    {
        $frame = new Frame(6, 2);
        $context = new RenderContext(Theme::dark());
        $context->beginFrame(0.0);
        $context->focusOn(new FocusState(ComponentId::named('field')));

        (new MixedTreeApp())->render($frame, $context);

        self::assertSame('F', $frame->character(6), 'the unbound pane still shows the flag it was given');
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

final readonly class FocusProbe implements Widget
{
    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::exact(1));
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        $frame->putChar($area->x, $area->y, $context->showsFocus(true) ? 'F' : 'u');
    }
}
