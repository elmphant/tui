<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseButton;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Structure\Panel;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PressOnNothingFocusableTest extends TestCase
{
    #[Test]
    public function aPressOnAPlainRowOfAFormLeavesTheKeyboardOnItsField(): void
    {
        $harness = ModelHarness::for(new FormWithAPlainRow(), 30, 8);
        self::assertSame('form/0', $harness->focused()?->value);

        $harness->dispatch(new MouseMsg(3, 4, MouseAction::Press, MouseButton::Left));

        self::assertSame('form/0', $harness->focused()?->value);
    }

    #[Test]
    public function aPressOnAPlainRowOfAFormTheKeyboardIsOutsideOfBringsItIntoTheForm(): void
    {
        $harness = ModelHarness::for(new SearchBesideAForm(), 30, 10);
        self::assertSame('search', $harness->focused()?->value);

        $harness->click('plain');

        self::assertSame('form/0', $harness->focused()?->value);
    }
}

final readonly class SearchBesideAForm extends App
{
    public function __construct(public ?TextInput $search = null, public Panel $form = new Panel('Form'))
    {
    }

    public function view(): Widget
    {
        return Layout::vertical(
            Component::bind('search', $this->search ?? TextInput::new(), static fn (self $app, TextInput $search): self => new self($search, $app->form)),
            Component::bind(
                'form',
                $this->form->withChildren([TextInput::new(), Text::new('plain')]),
                static fn (self $app, Panel $form): self => new self($app->search, $form),
            ),
        );
    }
}

final readonly class FormWithAPlainRow extends App
{
    public function __construct(public Panel $form = new Panel('Form'))
    {
    }

    public function view(): Widget
    {
        return Component::bind(
            'form',
            $this->form->withChildren([TextInput::new(), TextInput::new(), Text::new('plain')]),
            static fn (self $app, Panel $form): self => new self($form),
        );
    }
}
