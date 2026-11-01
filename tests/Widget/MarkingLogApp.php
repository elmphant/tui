<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Structure\Panel;
use Elmphant\Tui\Widget\Widget;

final readonly class MarkingLogApp extends App
{
    private Panel $log;

    public function __construct(?Panel $log = null)
    {
        $this->log = $log ?? Panel::new('log')->withChildren(array_map(
            static fn (int $index): Text => Text::new("line{$index}"),
            range(0, 19),
        ));
    }

    public function view(): Widget
    {
        return Component::bind('log', $this->log, static fn (self $app, Panel $log): self => new self($log));
    }
}
