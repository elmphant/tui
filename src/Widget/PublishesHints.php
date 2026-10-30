<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget;

/**
 * Derive hints from active bindings so footers cannot retain stale key labels.
 */
interface PublishesHints extends Widget
{
    /**
     * @return array<string, string> key label => what it does
     */
    public function hints(): array;
}
