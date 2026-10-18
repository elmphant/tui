<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Stan\Fixtures;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Layout\SplitState;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Structure\Panel;
use Elmphant\Tui\Widget\Structure\Split;
use Elmphant\Tui\Widget\Widget;

final readonly class SplitBindingApp extends App
{
    public function __construct(public SplitState $state)
    {
    }

    public function view(): Widget
    {
        return Split::row($this->state, Text::new('a'), Text::new('b'));
    }

    public function bound(): Widget
    {
        return Component::bind('split', Split::column($this->state, Text::new('a')), static fn (self $app, Split $split): self => new self($split->state()));
    }

    public function boundThroughAVariable(): Widget
    {
        $split = Split::row($this->state, Text::new('a'))->withMinimums(3);

        return Component::bind('split', $split, static fn (self $app, Split $split): self => new self($split->state()));
    }

    public function insideABoundPanel(): Widget
    {
        return Component::bind('pane', Panel::new('pane')->add(Split::row($this->state, Text::new('a'))), static fn (self $app): self => $app);
    }
}
