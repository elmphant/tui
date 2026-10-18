<?php

declare(strict_types=1);

namespace Elmphant\Tui\Input;

trait KeybindingsAwareBehavior
{
    public function effectiveKeybindings(): Keybindings
    {
        $bindings = static::defaultKeybindings();
        if ($this->globalKeybindings !== null) {
            $globals = $this instanceof TakesText && $this->takesText()
                ? $this->globalKeybindings->withoutText()
                : $this->globalKeybindings;
            // Globals lead: they still only add keys, but a hint names the key an
            // action leads with, and what the host bound is what it shows.
            $bindings = $globals->append($bindings);
        }
        if ($this->customKeybindings !== null) {
            $bindings = $bindings->merge($this->customKeybindings);
        }

        return $bindings;
    }

    public function withKeybindings(?Keybindings $keybindings): static
    {
        return $this->cloneWith(customKeybindings: $keybindings);
    }

    public function withGlobalKeybindings(?Keybindings $keybindings): static
    {
        return $this->cloneWith(globalKeybindings: $keybindings);
    }
}
