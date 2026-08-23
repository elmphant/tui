<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Choice;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\MouseMsg;

/**
 * Addressing options by pointer across a frame boundary.
 *
 * A float's pointer events carry the popup as it was drawn, so optionAt() is
 * asked of that snapshot and the answer is applied to current state. The
 * address is the option's own name, never its row: a position addresses
 * something else the moment the content differs.
 */
interface PointerOptions
{
    public function optionAt(MouseMsg $message, Rect $area): ?string;

    public function hasOption(string $option): bool;

    public function activated(string $option): static;
}
