<?php

declare(strict_types=1);

namespace Elmphant\Tui\Runtime;

use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\FloatMouseMsg;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Rendering\FloatPlacement;

/**
 * @internal one routing rule for every runtime: stamp the screen rect, then
 * hand a pointer event to the float drawn over that cell
 *
 * The stamp exists because hit-testing and scroll-clamping inside a widget must
 * agree with the geometry the last render used
 */
final class InputRouter
{
    /**
     * @param list<FloatPlacement> $placements in paint order, so the last one covering a cell is the visible one
     *
     * @return Message|null null where the input must not reach the model at all
     */
    public static function route(Message $message, Rect $screen, array $placements): ?Message
    {
        if ($message instanceof KeyMsg) {
            return $message->isRelease() ? null : $message->withScreen($screen);
        }

        if (!$message instanceof MouseMsg) {
            return $message;
        }

        $stamped = $message->withScreen($screen);
        $topmost = self::topmostCovering($stamped, $placements);

        if ($topmost === null || !$topmost->float->captures($stamped->action)) {
            return $stamped;
        }

        return new FloatMouseMsg($stamped, $topmost);
    }

    /**
     * @param list<FloatPlacement> $placements
     */
    private static function topmostCovering(MouseMsg $mouse, array $placements): ?FloatPlacement
    {
        foreach (array_reverse($placements) as $placement) {
            if ($placement->contains($mouse->x, $mouse->y)) {
                return $placement;
            }
        }

        return null;
    }
}
