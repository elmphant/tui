<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Popup;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Context\PointerFloating;
use Elmphant\Tui\Widget\WidgetContainer;

/**
 * Let hosts customize popup decoration while exposing the same content and placement API.
 */
interface PopupFrame extends PointerFloating, WidgetContainer
{
    public function content(): DropdownContent;

    public function contentArea(Rect $area): Rect;
}
