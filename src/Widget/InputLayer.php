<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget;

/**
 * A widget that owns input while it is on screen.
 *
 * Components inside the topmost layer are the only ones the host binds:
 * everything behind it stops being focusable, tab-reachable and clickable
 * without the host unbinding anything itself.
 */
interface InputLayer
{
}
