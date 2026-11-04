<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Focus;

/**
 * Only components in the topmost input layer that claims input can receive focus, Tab or clicks.
 */
interface InputLayer
{
    public function claimsInput(): bool;
}
