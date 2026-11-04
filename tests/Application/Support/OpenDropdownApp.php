<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application\Support;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Widget\Choice\Select;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Popup\Dropdown;
use Elmphant\Tui\Widget\Widget;

final readonly class OpenDropdownApp extends App
{
    public Dropdown $dropdown;

    public function __construct(?Dropdown $dropdown = null)
    {
        $this->dropdown = $dropdown ?? Dropdown::new(Select::new(['alpha', 'beta', 'gamma']))->withOpen();
    }

    public function view(): Widget
    {
        return Layout::column(
            Component::bind('choice', $this->dropdown, static fn (self $app, Dropdown $dropdown): self => new self($dropdown)),
        );
    }
}
