<?php

declare(strict_types=1);

namespace Elmphant\Tui\Core;

use Elmphant\Tui\Support\NamedIdentifier;
use Stringable;

final readonly class ComponentId implements Stringable
{
    use NamedIdentifier;

    private const LABEL = 'Component ID';

    public function isUnder(self $ancestor): bool
    {
        return str_starts_with($this->value, $ancestor->value . '/');
    }

    public function parent(): ?self
    {
        $cut = strrpos($this->value, '/');

        return $cut === false ? null : new self(substr($this->value, 0, $cut));
    }

    /**
     * Ask only where `isUnder()` holds — that is what makes the answer non-empty.
     *
     * @return non-empty-list<int>
     */
    public function pathBelow(self $ancestor): array
    {
        return array_map(intval(...), explode('/', substr($this->value, strlen($ancestor->value) + 1)));
    }
}
