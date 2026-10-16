<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Layout\Layout;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Structure\Panel;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FocusScopeLandingTest extends TestCase
{
    #[Test]
    public function aFieldLeavingAScopedPaneLeavesTheKeyboardOnItsSiblingInThatPane(): void
    {
        $harness = ModelHarness::for(ScopedFiltersApp::seeded(), 40, 12)->initialize();
        $harness->dispatch(new KeyMsg('f', ctrl: true));
        self::assertSame('filters/1', $harness->focused()?->value);

        $harness->dispatch(new KeyMsg('d', ctrl: true));

        self::assertSame('filters/0', $harness->focused()?->value);
    }
}

final readonly class ScopedFiltersApp extends App
{
    public function __construct(
        public Panel $filters,
        public TextInput $timeline,
    ) {
    }

    public static function seeded(): self
    {
        return new self(Panel::new('Filters')->add(TextInput::new()->withValue('a'))->add(TextInput::new()->withValue('b')), TextInput::new());
    }

    public function view(): Widget
    {
        return Layout::vertical(
            new FilterRowScope(Component::bind(
                'filters',
                $this->filters,
                static fn (self $app, Panel $panel): self => $app->cloneWith(filters: $panel),
            )),
            Component::bind(
                'timeline',
                $this->timeline,
                static fn (self $app, TextInput $field): self => $app->cloneWith(timeline: $field),
            ),
        );
    }

    protected function applicationKeybindings(): Keybindings
    {
        return new Keybindings(['quit' => ['ctrl+q'], 'into' => ['ctrl+f'], 'drop' => ['ctrl+d']]);
    }

    protected function onAction(string $action, KeyMsg $message): UpdateResult
    {
        return match ($action) {
            'into' => new UpdateResult($this, Cmd::focus(ComponentId::named('filters/1'))),
            'drop' => UpdateResult::keep($this->cloneWith(filters: $this->filters->withChildren([TextInput::new()->withValue('a')]))),
            default => parent::onAction($action, $message),
        };
    }
}
