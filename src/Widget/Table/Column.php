<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Table;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Support\Cloneable;
use Elmphant\Tui\Widget\Wrap;

final readonly class Column
{
    use Cloneable;

    private function __construct(
        private string $title,
        private Constraint $width,
        private Wrap $wrap = Wrap::WordWrap,
    ) {
    }

    public static function new(string $title, Constraint $width): self
    {
        return new self($title, $width);
    }

    /**
     * NoWrap keeps each line of a cell on one row and cuts it with an ellipsis,
     * so one long cell cannot make its row tall.
     */
    public function withWrap(Wrap $wrap): self
    {
        return $this->cloneWith(wrap: $wrap);
    }

    public function title(): string
    {
        return $this->title;
    }

    public function width(): Constraint
    {
        return $this->width;
    }

    public function wrap(): Wrap
    {
        return $this->wrap;
    }
}
