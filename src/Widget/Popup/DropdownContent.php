<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Popup;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Widget\Focusable;
use Elmphant\Tui\Widget\HoldsAValue;

/**
 * Dropdown content supplies focus, caption, size and click lookup against the drawn frame.
 * Content without Committing supports multiple choice.
 */
interface DropdownContent extends Focusable, HoldsAValue
{
    public function caption(): string;

    public function hasChoice(): bool;

    /**
     * Use unconstrained content width for popups, rather than the space-sharing rules in Sizing.
     */
    public function popupWidth(): int;

    public function popupHeight(int $width): int;

    /**
     * Resolve the drawn option to its stable value, so it still identifies the same option after rows move.
     */
    public function optionAt(MouseMsg $message, Rect $area): ?string;

    public function hasOption(string $option): bool;

    public function activated(string $option): static;
}
