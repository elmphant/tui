<?php

declare(strict_types=1);

namespace Elmphant\Tui\Runtime;

use Elmphant\Tui\Core\FocusState;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Input\KeyHint;
use Elmphant\Tui\Message\KeyMsg;

interface DescribesKeys
{
    public function opensKeyHelp(KeyMsg $key, FocusState $focus): bool;

    /**
     * @return array<string, list<KeyHint>> group title => its keys
     */
    public function keyHelp(FocusState $focus): array;

    /**
     * The app's keys for searching and moving in the help. Without `Actions::SEARCH`, typing goes straight to the filter.
     */
    public function keyHelpKeys(): Keybindings;
}
