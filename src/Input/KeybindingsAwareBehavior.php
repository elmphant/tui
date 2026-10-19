<?php

declare(strict_types=1);

namespace Elmphant\Tui\Input;

trait KeybindingsAwareBehavior
{
    /**
     * A host's own bindings replace the defaults per action; the globals add to
     * whatever is left, so overriding one widget never switches them off for it.
     */
    public function effectiveKeybindings(): Keybindings
    {
        $bindings = static::defaultKeybindings();
        if ($this instanceof BindsForTheMoment) {
            // Leading, so a key such as Esc means what it means in this state.
            $bindings = $this->momentaryKeybindings()->append($bindings);
        }
        if ($this->customKeybindings !== null) {
            $bindings = $bindings->merge($this->customKeybindings);
        }
        if ($this->globalKeybindings === null) {
            return $bindings;
        }

        // A field keeps its own line-editing keys, as it keeps every text key.
        $globals = $this instanceof TakesText && $this->takesText()
            ? $this->globalKeybindings->withoutText()->except($bindings)
            : $this->globalKeybindings;

        // Globals lead: a hint names the key an action leads with, and what the
        // host bound is what it shows.
        return $globals->append($bindings);
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
