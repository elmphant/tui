<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Core;

use Elmphant\Tui\Core\App;
use Elmphant\Tui\Core\Component;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Structure\Panel;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Whatever sits between a pane and a field, the field is a stop of its own.
 */
final class NestedStopsTest extends TestCase
{
    #[Test]
    public function aFieldInAPaneInsideAPaneIsAStopOfItsOwn(): void
    {
        $harness = ModelHarness::for(PaneInPaneApp::seeded(), 40, 10);
        $model = $harness->model();
        self::assertInstanceOf(PaneInPaneApp::class, $model);

        self::assertSame(
            ['outer/0/0', 'outer/0/1'],
            array_map(static fn (ComponentId $id): string => $id->value, $model->focusableIds()),
        );

        $harness->dispatch(new KeyMsg('x'));

        $model = $harness->model();
        self::assertInstanceOf(PaneInPaneApp::class, $model);
        self::assertSame(['x', ''], $model->innerValues());
    }
}

final readonly class PaneInPaneApp extends App
{
    public function __construct(public Panel $outer = new Panel())
    {
    }

    public static function seeded(): self
    {
        return new self(Panel::new('Outer')->add(Panel::new('Inner')->add(TextInput::new())->add(TextInput::new())));
    }

    /**
     * @return list<string>
     */
    public function innerValues(): array
    {
        $inner = [...$this->outer->children()][0];

        if (!$inner instanceof Panel) {
            return ['?'];
        }

        return array_map(
            static fn (Widget $field): string => $field instanceof TextInput ? $field->value() : '?',
            [...$inner->children()],
        );
    }

    public function view(): Widget
    {
        return Component::bind('outer', $this->outer, static fn (self $app, Panel $outer): self => $app->cloneWith(outer: $outer));
    }
}
