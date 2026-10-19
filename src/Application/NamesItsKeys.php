<?php

declare(strict_types=1);

namespace Elmphant\Tui\Application;

/**
 * A mounted model that answers keys of its own, outside any component: the key
 * help lists them under the mount's name while the model hears keys.
 */
interface NamesItsKeys
{
    /**
     * Derived from the bindings the model matches, as a footer hint is.
     *
     * @return array<string, string> key label => what it does
     */
    public function ownKeys(): array;
}
