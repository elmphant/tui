<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Focus;

/**
 * While this answers true, a key the widget leaves alone goes to nobody else.
 */
interface CapturesKeys
{
    public function capturesKeys(): bool;
}
