<?php

declare(strict_types=1);

namespace Elmphant\Tui\Layout;

final readonly class Sizing
{
    private function __construct(
        public Constraint $columns,
        public Constraint $rows,
    ) {
    }

    public static function of(Constraint $columns, Constraint $rows): self
    {
        return new self($columns, $rows);
    }

    /**
     * @internal the allocators' view; applications read $columns / $rows
     */
    public function on(Direction $axis): Constraint
    {
        return $axis === Direction::Horizontal ? $this->columns : $this->rows;
    }
}
