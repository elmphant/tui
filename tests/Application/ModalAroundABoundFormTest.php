<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Form\Field;
use Elmphant\Tui\Widget\Form\Form;
use Elmphant\Tui\Widget\Popup\Modal;
use Elmphant\Tui\Widget\Popup\Overlay;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ModalAroundABoundFormTest extends TestCase
{
    #[Test]
    public function aModalSizedToItsContentFitsEveryFieldOfABoundForm(): void
    {
        $harness = ModelHarness::for(new BoundFormInModalApp(), width: 80, height: 30)->initialize();

        self::assertNotNull($harness->renderScreen()->find('Delta'));
    }

    #[Test]
    public function tabWalksTheFieldsOfABoundFormAndNeverTheModalAroundThem(): void
    {
        $harness = ModelHarness::for(new BoundFormInModalApp(), width: 80, height: 30)->initialize();
        $visited = [];

        for ($step = 0; $step < 6; ++$step) {
            $visited[] = $harness->focused()?->value;
            $harness->press('tab');
        }

        self::assertSame('form/1', $visited[0]);
        self::assertNotContains('modal', $visited);
    }
}

final readonly class BoundFormInModalApp extends App
{
    public function __construct(public ?Form $form = null)
    {
    }

    public function view(): Widget
    {
        $form = $this->form ?? Form::new()
            ->withField(Field::text('a', 'Alpha'))
            ->withField(Field::text('b', 'Bravo'))
            ->withField(Field::text('c', 'Charlie'))
            ->withField(Field::text('d', 'Delta'))
            ->withSubmit('Save', new FormInModalSubmitted());

        return Overlay::new(
            Text::new('background'),
            Component::bind(
                'modal',
                Modal::new('Edit', Component::bind('form', $form, static fn (self $app, Form $form): self => new self($form)))->withSizedToContent(),
                static fn (self $app): self => $app,
            ),
        );
    }
}

final readonly class FormInModalSubmitted implements Message
{
}
