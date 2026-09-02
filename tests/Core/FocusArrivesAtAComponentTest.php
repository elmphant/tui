<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Core;

use Elmphant\Tui\Core\App;
use Elmphant\Tui\Core\Component;
use Elmphant\Tui\Layout\Layout;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Structure\Panel;
use Elmphant\Tui\Widget\Widget;
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
        return Layout::vertical(
            Component::bind('left', $this->left, static fn (self $app, Panel $pane): self => $app->cloneWith(left: $pane)),
            Component::bind('right', $this->right, static fn (self $app, Panel $pane): self => $app->cloneWith(right: $pane)),
        );
    }
}
