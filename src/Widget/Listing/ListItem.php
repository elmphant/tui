<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Listing;

interface ListItem
{
    public function title(): string;

    public function description(): string;

    public function filterValue(): string;

    /**
     * Use a stable key so renamed items retain cursor and selection, and identical labels stay distinct.
     */
    public function key(): string;

    /**
     * Delegate height measurement to ListItemLayout so wrapping matches rendering.
     */
    public function measureHeight(int $width): int;

    /**
     * Whether the item is drawn on a pulsing band of the theme's warning colour.
     */
    public function pulsing(): bool;
}
