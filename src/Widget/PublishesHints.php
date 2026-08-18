<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget;

/**
 * A widget that names the keys it answers, so a footer can carry them instead of
 * repeating a copy that outlives the binding it came from.
 */
interface PublishesHints extends Widget
{
    /**
     * @return array<string, string> key label => what it does
     */
    public function hints(): array;
}
