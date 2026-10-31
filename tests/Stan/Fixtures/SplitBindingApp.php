<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Stan\Fixtures;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Layout\SplitState;
use Elmphant\Tui\Widget\Layout\Layout;
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

    public function boundAndLooseSplitsSideBySide(): Widget
    {
        $kept = Split::row($this->state, Text::new('a'));
        $loose = Split::column($this->state, Text::new('b'));

        return Layout::vertical(
            Component::bind('kept', $kept, static fn (self $app, Split $split): self => new self($split->state())),
            Component::bind('pane', Panel::new('pane'), static fn (self $app): self => $app),
            $loose,
            Split::row($this->state, Text::new('c')),
        );
    }
}
