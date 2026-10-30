<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Application\ComponentRegistry;
use Elmphant\Tui\Application\Mount;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\FocusState;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\Reducer;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Structure\Panel;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MountedSubModelTest extends TestCase
{
    #[Test]
    public function theAppPublishesAComponentBoundByAMountedSubModel(): void
    {
        $app = new MountingApp(new MountedPanel(TextInput::new()->withValue('before')));

        $component = ComponentRegistry::fromWidget($app->view())->get(ComponentId::named('sub/field'));

        self::assertNotNull($component, 'the walk has to reach through the mount');

        $updated = $component->replaceIn($app, TextInput::new()->withValue('after'), new KeyMsg('x'))->model;

        self::assertInstanceOf(MountingApp::class, $updated);
        self::assertSame('after', $updated->panel->field->value());
    }

    #[Test]
    public function anAppWhoseViewIsAMountDrawsWhatTheMountHolds(): void
    {
        $harness = ModelHarness::for(new MountingApp(new MountedPanel(TextInput::new()->withValue('mounted'))), 20, 1);

        self::assertStringContainsString('mounted', $harness->renderScreen()->plainText());
    }

    #[Test]
    public function aGraftedChildOfAMountedPaneWritesBackThroughTheMount(): void
    {
        $app = new MountingFormApp(new MountedForm(Panel::new('Sub')->add(TextInput::new())->add(TextInput::new())));

        $updated = $app->update(
            new KeyMsg('x'),
            Rect::fromSize(40, 10),
            new FocusState(ComponentId::named('sub/form/0')),
        )->model;

        self::assertInstanceOf(MountingFormApp::class, $updated);
        $first = [...$updated->form->form->children()][0];
        self::assertInstanceOf(TextInput::class, $first);
        self::assertSame('x', $first->value());
    }
}

final readonly class MountingFormApp extends App
{
    public function __construct(public MountedForm $form)
    {
    }

    public function view(): Widget
    {
        return Mount::named(
            'sub',
            $this->form,
            $this->form->view(),
            static fn (self $app, MountedForm $form): self => new self($form),
        );
    }
}

final readonly class MountedForm implements Reducer
{
    public function __construct(public Panel $form)
    {
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return new UpdateResult($this);
    }

    public function view(): Widget
    {
        return Component::bind(
            'form',
            $this->form,
            static fn (self $model, Panel $form): self => new self($form),
        );
    }
}

final readonly class MountingApp extends App
{
    public function __construct(public MountedPanel $panel)
    {
    }

    public function withPanel(MountedPanel $panel): self
    {
        return new self($panel);
    }

    public function view(): Widget
    {
        return Mount::named(
            'sub',
            $this->panel,
            $this->panel->view(),
            static fn (self $app, MountedPanel $panel): self => $app->withPanel($panel),
        );
    }
}

final readonly class MountedPanel implements Reducer
{
    public function __construct(public TextInput $field)
    {
    }

    public function withField(TextInput $field): self
    {
        return new self($field);
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return new UpdateResult($this);
    }

    public function view(): Widget
    {
        return Component::bind(
            'field',
            $this->field,
            static fn (self $panel, TextInput $field): self => $panel->withField($field),
        );
    }
}
