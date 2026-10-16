<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Focus;

/**
 * A container that keeps the child the keyboard is in on screen. The runtime
 * tells it on arrival and never on departure; null is the container with no
 * child to follow.
 */
interface FollowsTheKeyboard
{
    public function withKeyboardAt(?int $child): static;
}
