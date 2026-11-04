<?php

declare(strict_types=1);

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Runtime\ScreenMode;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Prose\Paragraph;
use Elmphant\Tui\Widget\Widget;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

final readonly class InlineE2eApp extends App
{
    public TextInput $field;

    public function __construct(?TextInput $field = null)
    {
        $this->field = $field ?? TextInput::new()->withPlaceholder('your name');
    }

    public function view(): Widget
    {
        return Layout::column(
            Paragraph::fromText('Who are you?'),
            Component::bind('name', $this->field, static fn (self $app, TextInput $field): self => new self($field)),
            Paragraph::fromText('Enter to finish'),
        );
    }

    protected function onKey(KeyMsg $message, Rect $screen): UpdateResult
    {
        return $message->key === Key::Enter ? new UpdateResult($this, Cmd::quit()) : UpdateResult::ignored($this);
    }
}

echo "Inline heading\n";

$app = Program::new(new InlineE2eApp())->withScreenMode(ScreenMode::inline())->start();

echo 'Name: ' . $app->field->value() . "\n";
