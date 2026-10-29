<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Application\Mount;
use Elmphant\Tui\Core\Cmd;
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

final class WriteBackMessageTest extends TestCase
{
    #[Test]
    public function aFieldInsideABoundPanelHandsTheWriteBackTheKeyItAnswered(): void
    {
        $app = ModelHarness::for(new PanelHost(Panel::new('Form')->add(TextInput::new())), width: 40, height: 6)->type('a')->model();

        self::assertInstanceOf(PanelHost::class, $app);
        self::assertInstanceOf(KeyMsg::class, $app->heard);
        self::assertSame('a', $app->heard->key);
    }

    #[Test]
    public function aFieldInsideAMountHandsTheWriteBackTheKeyItAnswered(): void
    {
        $app = ModelHarness::for(new MountHost(new FieldModel(TextInput::new())), width: 40, height: 6)->type('a')->model();

        self::assertInstanceOf(MountHost::class, $app);
        self::assertInstanceOf(KeyMsg::class, $app->field->heard);
        self::assertSame('a', $app->field->heard->key);
    }
}

final readonly class PanelHost extends App
{
    public function __construct(public Panel $form, public ?Message $heard = null)
    {
    }

    public function view(): Widget
    {
        return Component::bind('form', $this->form, static fn (self $app, Panel $form, Message $message): self => new self($form, $message));
    }
}

final readonly class FieldModel implements Reducer
{
    public function __construct(public TextInput $input, public ?Message $heard = null)
    {
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return UpdateResult::ignored($this);
    }

    public function view(): Widget
    {
        return Component::bind('input', $this->input, static fn (self $model, TextInput $input, Message $message): self => new self($input, $message));
    }
}

final readonly class MountHost extends App
{
    public function __construct(public FieldModel $field)
    {
    }

    public function view(): Widget
    {
        return Mount::named('field', $this->field, $this->field->view(), static fn (self $app, FieldModel $field): self => new self($field));
    }

    protected function setup(): ?Cmd
    {
        return Cmd::focus('field/input');
    }
}
