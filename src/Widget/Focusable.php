<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget;

interface Focusable extends Interactive
{
    public function isFocused(): bool;

    public function withFocus(bool $focused): static;
}
