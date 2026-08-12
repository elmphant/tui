<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Stan\Fixtures;

use Elmphant\Tui\Core\Cloneable;

final readonly class NamedRebuilder
{
    use Cloneable;

    public function __construct(
        public string $label = '',
        public int $width = 0,
        public bool $wrap = false,
    ) {
    }

    public function withLabel(string $label): self
    {
        return new self(label: $label, width: $this->width, wrap: $this->wrap);
    }

    public function withWrap(bool $wrap): self
    {
        return $this->cloneWith(wrap: $wrap);
    }
}
