<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Structure;

use Elmphant\Tui\Support\Cloneable;
use Elmphant\Tui\Widget\Widget;

final readonly class TabPage
{
    use Cloneable;

    public ScrollPane $pane;

    public function __construct(
        public string $label,
        ?ScrollPane $pane = null,
        private ?string $key = null,
    ) {
        $this->pane = $pane ?? ScrollPane::of();
    }

    public function withLabel(string $label): self
    {
        return $this->cloneWith(label: $label);
    }

    /**
     * What the selection follows across TabBar::withTabs(), for a label that
     * changes while the page stays the same: `Work (1)` becoming `Work (2)`.
     */
    public function withKey(?string $key): self
    {
        return $this->cloneWith(key: $key);
    }

    /**
     * The key given, else the label.
     */
    public function key(): string
    {
        return $this->key ?? $this->label;
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
