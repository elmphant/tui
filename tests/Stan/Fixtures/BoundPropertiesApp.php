<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Stan\Fixtures;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Widget;

final readonly class BoundPropertiesApp extends App
{
    public function __construct(
        private TextInput $name,
        private CounterModel $counter,
        private TextInput $forgotten,
    ) {
    }

    public function view(): Widget
    {
        return Layout::column($this->bind('name'), $this->mount('counter', Text::new('counter')));
    }
}

final readonly class CounterModel extends App
{
    public function view(): Widget
    {
        return Text::new('0');
    }
}
