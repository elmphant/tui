<?php

declare(strict_types=1);

namespace Elmphant\Tui\Input;

interface KeybindingsAware
{
    public static function defaultKeybindings(): Keybindings;

    public function effectiveKeybindings(): Keybindings;

    public function withKeybindings(?Keybindings $keybindings): static;

    public function withGlobalKeybindings(?Keybindings $keybindings): static;
}
