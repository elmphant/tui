<?php

declare(strict_types=1);

namespace Elmphant\Tui\Input;

trait KeybindingsAwareBehavior
{
    /**
     * Local overrides replace defaults per action; globals add to the remaining bindings.
     */
    public function effectiveKeybindings(): Keybindings
    {
        $bindings = static::defaultKeybindings();
        if ($this instanceof BindsForTheMoment) {
            // Leading, so a key such as Esc means what it means in this state.
            $bindings = $this->momentaryKeybindings()->plus($bindings);
        }
        if ($this->customKeybindings !== null) {
            $bindings = $bindings->overriddenBy($this->customKeybindings);
        }
        if ($this->globalKeybindings === null) {
            return $bindings;
        }

        // A field keeps its own line-editing keys, as it keeps every text key.
        $globals = $this instanceof TakesText && $this->takesText()
            ? $this->globalKeybindings->withoutText()->except($bindings)
            : $this->globalKeybindings;

        // Put global bindings first so hints show the host's preferred key.
        return $globals->plus($bindings);
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
