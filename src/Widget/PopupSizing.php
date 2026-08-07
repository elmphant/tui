<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget;

/**
 * How big a widget would be with nothing constraining it, so a float can be
 * drawn around it.
 *
 * Deliberately not MeasureHeight and MeasureWidth: those are the layout
 * protocol, and Layout, VerticalStack, Flex and Container all prefer them over
 * a widget's constraint(). A widget that scrolls asks a stack for a share of
 * the room and a float for all of it — two questions, so two names.
 */
interface PopupSizing
{
    public function popupWidth(): int;

    public function popupHeight(int $width): int;
}
