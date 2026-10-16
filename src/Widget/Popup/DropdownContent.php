<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Popup;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Widget\Focusable;

/**
 * What a Dropdown can hold: focus to receive keys, a caption for the closed row,
 * a size to draw the popup at, and pointer addressing to resolve a click against
 * the frame the reader saw.
 *
 * Committing is not part of this: content without it is multiple choice.
 */
interface DropdownContent extends Focusable
{
    /**
     * What the widget reads as when it is collapsed behind the trigger row.
     */
    public function caption(): string;

    /**
     * Whether that caption names something chosen rather than standing in for a
     * choice not yet made.
     */
    public function hasChoice(): bool;

    /**
     * How wide the widget would be with nothing constraining it. Not its Sizing:
     * a stack gives a scrolling widget a share of the room, a float all of it.
     */
    public function popupWidth(): int;

    public function popupHeight(int $width): int;

    /**
     * Asked of the popup as it was drawn; the answer is the option's own name,
     * never its row, so it still addresses the same option in current state.
     */
    public function optionAt(MouseMsg $message, Rect $area): ?string;

    public function hasOption(string $option): bool;

    public function activated(string $option): static;
}
