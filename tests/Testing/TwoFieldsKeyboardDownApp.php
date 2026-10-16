<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Testing;

use Elmphant\Tui\Core\App;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Component;
use Elmphant\Tui\Layout\Layout;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Widget;

final readonly class TwoFieldsKeyboardDownApp extends App
{
    public function __construct(
        public TextInput $first,
        public TextInput $second,
    ) {
    }

    public function view(): Widget
    {
        return Layout::vertical(
            Component::bind('first', $this->first, static fn (self $app, TextInput $field): self => $app->cloneWith(first: $field)),
            Component::bind('second', $this->second, static fn (self $app, TextInput $field): self => $app->cloneWith(second: $field)),
        );
    }

    protected function setup(): ?Cmd
    {
        return Cmd::focusNothing();
    }
}
