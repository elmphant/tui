<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Core;

use Elmphant\Tui\Core\App;
use Elmphant\Tui\Core\Component;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\ComponentRegistry;
use Elmphant\Tui\Core\Mount;
use Elmphant\Tui\Core\Reducer;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Widget\TextInput;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A mounted sub-model publishes its own components to the App above it, and a
 * replacement travels back down to the sub-model that bound it.
 */
final class MountedSubModelTest extends TestCase
{
    #[Test]
    public function theAppPublishesAComponentBoundByAMountedSubModel(): void
    {
        $app = new MountingApp(new MountedPanel(TextInput::new()->withValue('before')));

        $component = ComponentRegistry::fromWidget($app->view())->get(ComponentId::named('field'));

        self::assertNotNull($component, 'the walk has to reach through the mount');

        $updated = $component->replaceIn($app, TextInput::new()->withValue('after'));

        self::assertInstanceOf(MountingApp::class, $updated);
        self::assertSame('after', $updated->panel->field->value());
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
        return Mount::of(
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
