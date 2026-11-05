<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Focus;

/**
 * A widget hears the mouse only inside its area. While this answers true it hears it everywhere:
 * to follow a drag it started, or to close when the reader clicks somewhere else.
 */
interface CapturesThePointer
{
    public function capturesThePointer(): bool;
}
