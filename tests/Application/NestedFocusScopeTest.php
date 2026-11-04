<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\FocusState;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NestedFocusScopeTest extends TestCase
{
    #[Test]
    public function aScopeNestedInTheHeldOneIsOffItsRing(): void
    {
        $app = new NestedScopesApp(TextInput::new(), TextInput::new(), TextInput::new(), TextInput::new());

        self::assertSame(
            ['outer'],
            array_map(
                static fn (ComponentId $id): string => $id->value,
                $app->ringIds(FocusState::on(ComponentId::named('outer'))),
            ),
        );
    }
}

final readonly class NestedScopesApp extends App
{
    public function __construct(
        public TextInput $outer,
        public TextInput $innerA,
        public TextInput $innerB,
        public TextInput $pane,
    ) {
    }

    public function view(): Widget
    {
        return Layout::vertical(
            new FilterRowScope(Layout::horizontal(
                Component::bind('outer', $this->outer, static fn (self $app, TextInput $field): self => $app->cloneWith(outer: $field)),
                new FilterRowScope(Layout::horizontal(
                    Component::bind('innerA', $this->innerA, static fn (self $app, TextInput $field): self => $app->cloneWith(innerA: $field)),
                    Component::bind('innerB', $this->innerB, static fn (self $app, TextInput $field): self => $app->cloneWith(innerB: $field)),
                )),
            )),
            Component::bind('pane', $this->pane, static fn (self $app, TextInput $field): self => $app->cloneWith(pane: $field)),
        );
    }
}
