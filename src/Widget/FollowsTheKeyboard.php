<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget;

/**
 * A container that keeps the child the keyboard is in on screen. The runtime
 * tells it on arrival; null says the keyboard is not below it.
 */
interface FollowsTheKeyboard
{
    public function withKeyboardAt(?int $child): static;
}
