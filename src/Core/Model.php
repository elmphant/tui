<?php

declare(strict_types=1);

namespace Elmphant\Tui\Core;

use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;

interface Model extends Reducer
{
    public function init(): ?Cmd;

    public function render(Frame $frame, RenderContext $context): void;
}
