<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget;

/**
 * What a Dropdown can hold. Every capability is required by something the
 * container does: focus to receive keys, a caption for the closed row, both
 * measurements to size the popup, and pointer addressing to resolve a click
 * against the frame the reader saw.
 *
 * Committing is deliberately not part of this: lacking it is not a defect, it
 * is what makes content multiple choice.
 */
interface DropdownContent extends Focusable, Captioning, PopupSizing, PointerOptions
{
}
