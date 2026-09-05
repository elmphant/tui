<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget;

/**
 * A container whose focusable children are each a stop on the ring in their own
 * right, rather than being reached through it.
 *
 * The index counts children() in the order it yields them.
 */
interface GraftsItsChildren extends WidgetContainer
{
    public function withChildAt(int $index, Widget $child): static;
}
