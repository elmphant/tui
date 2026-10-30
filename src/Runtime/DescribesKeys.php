<?php

declare(strict_types=1);

namespace Elmphant\Tui\Runtime;

use Elmphant\Tui\Core\FocusState;
use Elmphant\Tui\Message\KeyMsg;

interface DescribesKeys
{
    public function opensKeyHelp(KeyMsg $key, FocusState $focus): bool;

    /**
     * @return array<string, array<array-key, string>> group title => key label => what it does
     */
    public function keyHelp(FocusState $focus): array;
}
