<?php

declare(strict_types=1);

namespace Elmphant\Tui\Application;

use Elmphant\Tui\Core\Cmd;

/**
 * A mounted model with work to start when the App starts.
 */
interface Initializes
{
    public function init(): ?Cmd;
}
