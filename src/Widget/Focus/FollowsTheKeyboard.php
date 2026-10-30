<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Focus;

/**
 * Notify containers on focus arrival, never departure; null means no child is focused.
 */
interface FollowsTheKeyboard
{
    public function withKeyboardAt(?int $child): static;
}
