<?php

declare(strict_types=1);

namespace Elmphant\Tui\Runtime;

use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\FocusState;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;

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

    /**
     * Every component the keyboard is allowed to be on, which is not the same as
     * where tab stops: a pointer-only pane is reached by a press and walked past.
     * Where a focus command has put the keyboard is checked against this.
     *
     * @return list<ComponentId>
     */
    public function reachableIds(): array;

    /**
     * The components $id sits inside, outermost first. When the keyboard's
     * component leaves, it lands in the nearest of these still on screen.
     *
     * @return list<ComponentId>
     */
    public function enclosingIds(ComponentId $id): array;
}
