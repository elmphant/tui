<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Core;

use Elmphant\Tui\Core\App;
use Elmphant\Tui\Core\Component;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Field\TextInput;
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
        $harness = ModelHarness::for(new BoundFieldFormApp(), 30, 6);

        self::assertSame('email', $harness->focused()?->value);
        self::assertSame(
            Theme::dark()->borderStyleFor(true)->foregroundValue(),
            $harness->renderFrame()->foregrounds[0],
        );
    }
}

final readonly class BoundFieldFormApp extends App
{
    public function __construct(public TextInput $email = new TextInput(), public int $scroll = 0)
    {
    }

    public function view(): Widget
    {
        $form = Panel::new('Form')
            ->add(Component::bind('email', $this->email, static fn (self $app, TextInput $email): self => $app->cloneWith(email: $email)))
            ->withScrollOffset($this->scroll);

        return Component::bind('form', $form, static fn (self $app, Panel $form): self => $app->cloneWith(scroll: $form->scrollOffset()));
    }
}
