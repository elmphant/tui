<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Structure;

use Elmphant\Tui\Support\Cloneable;
use Elmphant\Tui\Widget\Dispatch\ScrollPane;
use Elmphant\Tui\Widget\Widget;

final readonly class TabPage
{
    use Cloneable;

    public ScrollPane $pane;

    public function __construct(
        public string $label,
        ?ScrollPane $pane = null,
    ) {
        $this->pane = $pane ?? ScrollPane::of();
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
