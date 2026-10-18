<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Listing;

interface ListItem
{
    public function title(): string;

    public function description(): string;

    public function filterValue(): string;

    /**
     * What the cursor and the selection follow across ListView::withItems(): a
     * record's id, so a renamed item keeps them and two that read alike stay two.
     */
    public function key(): string;

    /**
     * How many rows this item occupies when drawn $width columns wide.
     *
     * Width matters because titles and descriptions wrap, so an item is taller
     * in a narrow pane. Implementations should delegate to ListItemLayout so
     * the count always matches what ListView draws.
     */
    public function height(int $width): int;
}
