<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Core;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\FocusState;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Structure\Panel;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ComponentDerivedStateTest extends TestCase
{
    /**
     * A model may keep what the component changed rather than the component, and
     * build the widget back from it — the same answer, a different instance.
     */
    #[Test]
    public function aComponentRebuiltFromWhatTheModelKeptIsAcceptedBack(): void
    {
        $scrolled = (new DerivedScrollApp())->update(
            new KeyMsg(Key::Down),
            new Rect(0, 0, 20, 5),
            new FocusState(ComponentId::named('pane')),
        );

        self::assertInstanceOf(DerivedScrollApp::class, $scrolled->model);
        self::assertSame(1, $scrolled->model->scroll);
    }

    /**
     * One key can change both the component's own state and something the host
     * derives from it, such as a title. The model kept the change, so the rebuilt
     * widget must be accepted — refusing it loses the keystroke.
     */
    #[Test]
    public function aComponentRebuiltWithDecorationTheSameKeystrokeChangedIsAcceptedBack(): void
    {
        $scrolled = (new DerivedTitleApp())->update(
            new KeyMsg(Key::Down),
            new Rect(0, 0, 20, 5),
            new FocusState(ComponentId::named('pane')),
        );

        self::assertInstanceOf(DerivedTitleApp::class, $scrolled->model);
        self::assertSame(1, $scrolled->model->scroll);
    }

    /**
     * The pane's own cursor is framework state the model has nowhere to keep.
     */
    #[Test]
    public function aPaneRebuiltFromTheModelsOwnDataSurvivesTabbingInsideIt(): void
    {
        $harness = ModelHarness::for(new RebuiltFieldsApp(), 40, 10);

        $harness->dispatch(new KeyMsg('x'));
        $harness->dispatch(new KeyMsg(Key::Tab));
        $harness->dispatch(new KeyMsg('y'));

        $model = $harness->model();
        self::assertInstanceOf(RebuiltFieldsApp::class, $model);
        self::assertSame(['x', 'y'], $model->values);
    }
}

final readonly class DerivedTitleApp extends App
{
    public function __construct(public int $scroll = 0)
    {
    }

    public function view(): Widget
    {
        return Component::bind(
            'pane',
            Panel::new('At row ' . $this->scroll)
                ->withChildren([
                    Text::new('one'),
                    Text::new('two'),
                    Text::new('three'),
                    Text::new('four'),
                    Text::new('five'),
                    Text::new('six'),
                ])
                ->withScrollOffset($this->scroll),
            static fn (self $app, Panel $pane): self => $app->cloneWith(scroll: $pane->scrollOffset()),
        );
    }
}

final readonly class DerivedScrollApp extends App
{
    public function __construct(public int $scroll = 0)
    {
    }

    public function view(): Widget
    {
        return Component::bind(
            'pane',
            Panel::new('Pane')
                ->withChildren([
                    Text::new('one'),
                    Text::new('two'),
                    Text::new('three'),
                    Text::new('four'),
                    Text::new('five'),
                    Text::new('six'),
                ])
                ->withScrollOffset($this->scroll),
            static fn (self $app, Panel $pane): self => $app->cloneWith(scroll: $pane->scrollOffset()),
        );
    }
}

/**
 * Rebuilding the widget from kept data is what view() is documented to do.
 */
final readonly class RebuiltFieldsApp extends App
{
    /**
     * @param list<string> $values
     */
    public function __construct(public array $values = ['', ''])
    {
    }

    public function view(): Widget
    {
        $panel = Panel::new('Form');

        foreach ($this->values as $value) {
            $panel = $panel->add(TextInput::new()->withValue($value));
        }

        return Component::bind('form', $panel, static function (self $app, Panel $panel): self {
            $values = [];

            foreach ($panel->children() as $child) {
                if ($child instanceof TextInput) {
                    $values[] = $child->value();
                }
            }

            return $app->cloneWith(values: $values);
        });
    }
}
