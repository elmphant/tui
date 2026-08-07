<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget;

/**
 * What a widget reads as when it is collapsed behind a trigger row.
 *
 * Derived from the state the widget already holds, never stored by a host:
 * a copy is what drifts. Distinct from FilterableList::summary(), which says
 * how much of a list a filter kept.
 */
interface Captioning
{
    public function caption(): string;

    /**
     * Whether that caption names something chosen rather than standing in for a
     * choice not yet made. Only the widget holding the choice can tell the two
     * apart, and a host that draws a prompt differently has to ask.
     */
    public function hasChoice(): bool;
}
