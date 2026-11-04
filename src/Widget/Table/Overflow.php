<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Table;

enum Overflow
{
    /**
     * Move them into the leading column, one line per field, their labels
     * stacked in that column's header. The table stays a grid at any width.
     */
    case Stack;

    /**
     * Keep every column and wrap the cells into whatever width is left.
     */
    case Wrap;
}
