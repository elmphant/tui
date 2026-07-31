<?php

declare(strict_types=1);

namespace Elmphant\Tui\Core;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\Message;

interface Reducer
{
    /**
     * @return UpdateResult<static>
     */
    public function update(Message $message, Rect $area): UpdateResult;
}
