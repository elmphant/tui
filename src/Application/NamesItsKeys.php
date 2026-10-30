<?php

declare(strict_types=1);

namespace Elmphant\Tui\Application;

/**
 * Include a mounted model's own key bindings in help while it receives keys.
 */
interface NamesItsKeys
{
    /**
     * Derive labels from active bindings; PHP converts numeric string keys such as `1` to integers.
     *
     * @return array<array-key, string> key label => what it does
     */
    public function ownKeys(): array;
}
