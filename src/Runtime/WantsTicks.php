<?php

declare(strict_types=1);

namespace Elmphant\Tui\Runtime;

use Elmphant\Tui\Core\Cmd;

interface WantsTicks
{
    /**
     * Asked whenever the model changes. A tick left out stops.
     */
    public function wantedTicks(): ?Cmd;
}
