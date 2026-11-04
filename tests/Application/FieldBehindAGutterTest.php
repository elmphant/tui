<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Structure\FocusGutter;
use Elmphant\Tui\Widget\Structure\Panel;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FieldBehindAGutterTest extends TestCase
{
    #[Test]
    public function aFieldBehindAFocusGutterIsAStopOfItsOwn(): void
    {
        $harness = ModelHarness::for(GutteredFormApp::seeded(), 30, 6);
        $model = $harness->model();
        self::assertInstanceOf(GutteredFormApp::class, $model);

        self::assertSame(
            ['form/0/0'],
            array_map(static fn (ComponentId $id): string => $id->value, $model->ringIds()),
        );

        $harness->dispatch(new KeyMsg('x'));

        $model = $harness->model();
        self::assertInstanceOf(GutteredFormApp::class, $model);
        self::assertSame('x', $model->fieldValue());
    }
}

final readonly class GutteredFormApp extends App
{
    public function __construct(public Panel $form = new Panel())
    {
    }

    public static function seeded(): self
    {
        return new self(Panel::new('Form')->add(FocusGutter::around(TextInput::new())));
    }

    public function fieldValue(): string
    {
        $gutter = [...$this->form->children()][0];
        $field = $gutter instanceof FocusGutter ? $gutter->content() : null;

        return $field instanceof TextInput ? $field->value() : '?';
    }

    public function view(): Widget
    {
        return Component::bind('form', $this->form, static fn (self $app, Panel $form): self => $app->cloneWith(form: $form));
    }
}
