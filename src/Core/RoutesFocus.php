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
     * The components the keyboard can walk from where it is, in tree order. A
     * focus scope holding it answers with its own controls, so tab stays inside
     * the chrome the reader stepped into.
     *
     * @return list<ComponentId>
     */
    public function focusableIds(FocusState $focus = new FocusState()): array;
}
