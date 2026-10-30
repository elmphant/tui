<?php

declare(strict_types=1);

namespace Elmphant\Tui\Application;

use Elmphant\Tui\Core\Cmd;

interface Initializes
{
    public function init(): ?Cmd;
}
