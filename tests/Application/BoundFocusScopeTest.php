<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\FocusState;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Focus\FocusScope;
use Elmphant\Tui\Widget\GraftsItsChildren;
use Elmphant\Tui\Widget\Interactive;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BoundFocusScopeTest extends TestCase
{
    #[Test]
    public function aBoundToolbarThatIsTheScopeKeepsItsControlsOffThePaneRing(): void
    {
        $ring = array_map(
            static fn (ComponentId $id): string => $id->value,
            (new BoundToolbarApp(new ScopedToolbar([TextInput::new(), TextInput::new()]), TextInput::new()))->focusableIds(new FocusState(ComponentId::named('pane'))),
        );

        self::assertSame(['pane'], $ring);
    }
}

final readonly class ScopedToolbar implements Interactive, GraftsItsChildren, FocusScope
{
    /**
     * @param list<Widget> $controls
     */
    public function __construct(private array $controls = [])
    {
    }

    public function sizing(): Sizing
    {
        return Sizing::of(rows: Constraint::exact(1));
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return UpdateResult::keep($this);
    }

    public function children(): iterable
    {
        return $this->controls;
    }

    public function placedChildren(Rect $area): iterable
    {
        return array_map(static fn (Widget $control): array => [$control, $area], $this->controls);
    }

    public function withChildAt(int $index, Widget $child): static
    {
        $controls = $this->controls;
        $controls[$index] = $child;

        return new static($controls);
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
    }
}

final readonly class BoundToolbarApp extends App
{
    public function __construct(
        public ScopedToolbar $toolbar,
        public TextInput $pane,
    ) {
    }

    public function view(): Widget
    {
        return Layout::vertical(
            Component::bind('toolbar', $this->toolbar, static fn (self $app, ScopedToolbar $toolbar): self => $app->cloneWith(toolbar: $toolbar)),
            Component::bind('pane', $this->pane, static fn (self $app, TextInput $field): self => $app->cloneWith(pane: $field)),
        );
    }
}
