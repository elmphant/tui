<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Tests\Application\Support\CopyableTestApp;
use Elmphant\Tui\Widget\Listing\ListView;
use Elmphant\Tui\Widget\Widget;

final readonly class ChordListApp extends CopyableTestApp
{
    public ListView $list;

    public function __construct(?ListView $list = null, private ?Keybindings $globals = null)
    {
        $this->list = $list ?? ListView::fromStrings(['one', 'two', 'three', 'four']);
    }

    public function view(): Widget
    {
        return Component::bind('list', $this->list, static fn (self $app, ListView $list): self => $app->cloneWith(list: $list));
    }

    protected function globalKeybindings(): Keybindings
    {
        return $this->globals ?? new Keybindings([Actions::HOME => ['g g']]);
    }
}
