<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget;

use Elmphant\Tui\Core\Cloneable;
use Elmphant\Tui\Widget\Dispatch\ScrollPane;

final readonly class TabPage
{
    use Cloneable;

    public function __construct(
        public string $label,
        public ScrollPane $pane = new ScrollPane(),
    ) {
    }

    public function withLabel(string $label): self
    {
        return $this->cloneWith(label: $label);
    }

    public function withPane(ScrollPane $pane): self
    {
        return $this->cloneWith(pane: $pane);
    }

    public function add(Widget $child): self
    {
        return $this->cloneWith(pane: $this->pane->add($child));
    }
}
