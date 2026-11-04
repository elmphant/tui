<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Application\InputContext;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Structure\Panel;
use Elmphant\Tui\Widget\Structure\ScrollPane;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FocusArrivesAtAComponentTest extends TestCase
{
    #[Test]
    public function aPaneTheKeyboardComesBackToStartsAtItsFirstChildAgain(): void
    {
        $harness = ModelHarness::for(TwoPaneForm::seeded(), 40, 20);

        foreach (['a', Key::Tab, 'b', Key::Tab, 'c', Key::Tab, 'd'] as $step) {
            $harness->dispatch(new KeyMsg($step));
        }

        $model = $harness->model();
        self::assertInstanceOf(TwoPaneForm::class, $model);
        self::assertSame(['c'], self::valuesOf($model->right), 'the keyboard passed through the right pane');
        self::assertSame(['ad', 'b'], self::valuesOf($model->left));
    }

    #[Test]
    #[DataProvider('waysToCarryAFocusMove')]
    public function aFocusMoveIsHonouredHoweverTheCommandCarriesIt(string $key): void
    {
        $harness = ModelHarness::for(new WrappedFocusApp(TextInput::new(), TextInput::new()), 40, 10);

        $harness->dispatch(new KeyMsg($key, ctrl: true));

        self::assertTrue(
            $harness->focused()?->equals(ComponentId::named('second')),
            'the move was honoured wherever it sat in the command',
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function waysToCarryAFocusMove(): iterable
    {
        yield 'batched with another effect' => ['b'];
        yield 'wrapped by an address' => ['m'];
    }

    #[Test]
    public function theHarnessPlacesTheKeyboardOnTheFirstFieldOfAPane(): void
    {
        $harness = ModelHarness::for(new SeededPaneForm(ScrollPane::of(TextInput::new(), TextInput::new())), 40, 10);

        $harness->dispatch(new KeyMsg('x'));

        $model = $harness->model();
        self::assertInstanceOf(SeededPaneForm::class, $model);
        self::assertSame(['x', ''], $model->fieldValues());
    }

    #[Test]
    public function tabWrapsBackToTheFirstFieldWhenThereIsOnlyOnePane(): void
    {
        $harness = ModelHarness::for(OnePaneForm::seeded(), 40, 20);

        foreach (['a', Key::Tab, 'b', Key::Tab, 'c'] as $step) {
            $harness->dispatch(new KeyMsg($step));
        }

        $model = $harness->model();
        self::assertInstanceOf(OnePaneForm::class, $model);
        self::assertSame(['ac', 'b'], self::valuesOf($model->form), 'the pane is the whole ring, so tab comes round');
    }

    /**
     * @return list<string>
     */
    private static function valuesOf(Panel $panel): array
    {
        $values = [];

        foreach ($panel->children() as $child) {
            if ($child instanceof TextInput) {
                $values[] = $child->value();
            }
        }

        return $values;
    }
}

final readonly class WrappedFocusApp extends App
{
    public function __construct(
        public TextInput $first,
        public TextInput $second,
    ) {
    }

    public function view(): Widget
    {
        return Layout::column(
            Component::bind('first', $this->first, static fn (self $app, TextInput $f): self => $app->cloneWith(first: $f)),
            Component::bind('second', $this->second, static fn (self $app, TextInput $f): self => $app->cloneWith(second: $f)),
        );
    }

    protected function onKey(KeyMsg $message, InputContext $input): UpdateResult
    {
        $move = Cmd::focus(ComponentId::named('second'));

        return match ($message->ctrl ? $message->key : null) {
            'b' => new UpdateResult($this, Cmd::batch(Cmd::tick(1.0), $move)),
            'm' => new UpdateResult($this, $move->map(static fn (Message $inner): Message => $inner)),
            default => new UpdateResult($this),
        };
    }
}

final readonly class SeededPaneForm extends App
{
    public function __construct(public ScrollPane $pane)
    {
    }

    public function view(): Widget
    {
        return Component::bind('pane', $this->pane, static fn (self $app, ScrollPane $pane): self => $app->cloneWith(pane: $pane));
    }

    /**
     * @return list<string>
     */
    public function fieldValues(): array
    {
        $values = [];

        foreach ($this->pane->children() as $child) {
            if ($child instanceof TextInput) {
                $values[] = $child->value();
            }
        }

        return $values;
    }
}

final readonly class OnePaneForm extends App
{
    public function __construct(public Panel $form = new Panel('Form'))
    {
    }

    public static function seeded(): self
    {
        return new self(Panel::new('Form')->add(TextInput::new())->add(TextInput::new()));
    }

    public function view(): Widget
    {
        return Component::bind('form', $this->form, static fn (self $app, Panel $pane): self => $app->cloneWith(form: $pane));
    }
}

final readonly class TwoPaneForm extends App
{
    public function __construct(
        public Panel $left = new Panel('Left'),
        public Panel $right = new Panel('Right'),
    ) {
    }

    public static function seeded(): self
    {
        return new self(
            Panel::new('Left')->add(TextInput::new())->add(TextInput::new()),
            Panel::new('Right')->add(TextInput::new()),
        );
    }

    public function view(): Widget
    {
        return Layout::column(
            Component::bind('left', $this->left, static fn (self $app, Panel $pane): self => $app->cloneWith(left: $pane)),
            Component::bind('right', $this->right, static fn (self $app, Panel $pane): self => $app->cloneWith(right: $pane)),
        );
    }
}
