<?php

declare(strict_types=1);

namespace Elmphant\Tui\Runtime;

use Elmphant\Tui\Core\FocusState;
use Elmphant\Tui\Message\KeyMsg;

/**
 * A model the runtime can show a key help for: what opens it, and what goes in it.
 */
interface DescribesKeys
{
    /**
     * Whether this key opens the key help where the keyboard is; a key a field takes as text never does.
     */
    public function opensKeyHelp(KeyMsg $key, FocusState $focus): bool;

    /**
     * Every key that works where the keyboard is, read off the live bindings.
     *
     * @return array<string, array<array-key, string>> group title => key label => what it does
     */
    public function keyHelp(FocusState $focus): array;
}
