<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application\Support;

use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Prose\Paragraph;
use Elmphant\Tui\Widget\Widget;

final readonly class FieldAboveALineApp extends CopyableTestApp
{
    public TextInput $field;

    public function __construct(?TextInput $field = null)
    {
        $this->field = $field ?? TextInput::new();
    }

    public function view(): Widget
    {
        return Layout::vertical(
            Component::bind('field', $this->field, static fn (self $app, TextInput $field): self => new self($field)),
            Paragraph::fromText('below'),
        );
    }
}
