<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application\Support;

use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Widget;

final readonly class QuitOnEnterFieldApp extends CopyableTestApp
{
    public TextInput $field;

    public function __construct(?TextInput $field = null)
    {
        $this->field = $field ?? TextInput::new();
    }

    public function view(): Widget
    {
        return Component::bind('field', $this->field, static fn (self $app, TextInput $field): self => new self($field));
    }

    protected function onKey(KeyMsg $message, Rect $screen): UpdateResult
    {
        return $message->key === Key::Enter ? new UpdateResult($this, Cmd::quit()) : UpdateResult::ignored($this);
    }
}
