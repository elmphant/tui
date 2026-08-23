<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget;

use Elmphant\Tui\Layout\Rect;

/**
 * The chrome drawn around content that floats above the layout.
 *
 * A Dropdown asks its frame where the content sits and what it holds, and never
 * which class drew it, so a host can put its own border, shadow or
 * heading around the same list.
 */
interface PopupFrame extends PointerFloating, WidgetContainer
{
    public function content(): DropdownContent;

    /**
     * Where the content goes once the frame has taken what it draws.
     */
    public function contentArea(Rect $area): Rect;
}
