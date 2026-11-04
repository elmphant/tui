<?php

declare(strict_types=1);

namespace Elmphant\Tui\Runtime;

use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\FocusState;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;

interface RoutesFocus extends Model
{
    public function update(Message $message, Rect $area, ?FocusState $focus = null): UpdateResult;

    /**
     * Return tab stops for the current focus scope, in tree order.
     *
     * @return list<ComponentId>
     */
    public function ringIds(?FocusState $focus = null): array;

    /**
     * Return all reachable components, including pointer-only panes that Tab skips; focus commands are checked against this list.
     *
     * @return list<ComponentId>
     */
    public function reachableIds(): array;

    /**
     * List ancestors outermost first so focus can return to a surviving container when its component disappears.
     *
     * @return list<ComponentId>
     */
    public function enclosingIds(ComponentId $id): array;
}
