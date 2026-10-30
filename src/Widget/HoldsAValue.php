<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget;

/**
 * Derive form values from widgets so stored copies cannot become stale.
 */
interface HoldsAValue extends Widget
{
    /**
     * @return string|bool|list<string>|null
     */
    public function value(): string|bool|array|null;

    /**
     * @param string|bool|list<string>|null $value
     *
     * @throws ValueItCannotHold for a value of another kind
     */
    public function withValue(string|bool|array|null $value): static;
}
