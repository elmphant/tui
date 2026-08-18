<?php

declare(strict_types=1);

namespace Elmphant\Tui\Core;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\Message;

/**
 * A model whose keyboard the runtime places, rather than the widgets deciding
 * among themselves.
 */
interface RoutesFocus extends Model
{
    public function update(Message $message, Rect $area, FocusState $focus = new FocusState()): UpdateResult;

    /**
     * The components that can hold the keyboard, in tree order.
     *
     * @return list<ComponentId>
     */
    public function focusableIds(): array;
}
