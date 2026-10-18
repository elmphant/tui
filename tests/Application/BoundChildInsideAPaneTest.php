<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Structure\Panel;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A child that keeps its own name inside a pane is reached by that name; the pane
 * around it still has to behave as it does around a bare child.
 */
final class BoundChildInsideAPaneTest extends TestCase
{
    #[Test]
    public function thePaneDrawsItsFocusedBorderWhileABoundChildHoldsTheKeyboard(): void
    {
        $harness = ModelHarness::for(new BoundFieldFormApp(TextInput::new()), 30, 6);

        self::assertSame('email', $harness->focused()?->value);
        self::assertSame(
            Theme::dark()->borderStyleFor(true)->foregroundValue(),
            $harness->renderFrame()->foregrounds[0],
        );
    }

    #[Test]
    public function aBoundFieldBelowTheFoldIsScrolledIntoViewOnArrival(): void
    {
        $harness = ModelHarness::for(BoundFieldBelowRowsApp::seeded(), 30, 6);

        self::assertSame('email', $harness->focused()?->value);
        self::assertStringContainsString('mail', $harness->renderScreen()->plainText());
    }
}

/**
 * Shaped like a host's pane: rows rebuilt from data every frame, the pane kept.
 */
final readonly class BoundFieldBelowRowsApp extends App
{
    public function __construct(public TextInput $email, public Panel $form = new Panel('Form'))
    {
    }

    public static function seeded(): self
    {
        return new self(TextInput::new()->withValue('mail'));
    }

    public function view(): Widget
    {
        $rows = array_map(static fn (int $row): Text => Text::new('row' . $row), range(1, 6));
        $rows[] = Component::bind('email', $this->email, static fn (self $app, TextInput $email): self => $app->cloneWith(email: $email));

        return Component::bind('form', $this->form->withChildren($rows), static fn (self $app, Panel $form): self => $app->cloneWith(form: $form));
    }
}

final readonly class BoundFieldFormApp extends App
{
    public function __construct(public TextInput $email, public int $scroll = 0)
    {
    }

    public function view(): Widget
    {
        $form = Panel::new('Form')
            ->add(Component::bind('email', $this->email, static fn (self $app, TextInput $email): self => $app->cloneWith(email: $email)))
            ->withScrollOffset($this->scroll);

        return Component::bind('form', $form, static fn (self $app, Panel $form): self => $app->cloneWith(scroll: $form->scrollOffset(Rect::fromSize(30, 6))));
    }
}
