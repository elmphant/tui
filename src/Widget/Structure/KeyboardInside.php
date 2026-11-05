<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Structure;

use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Widget\SingleMount;
use Elmphant\Tui\Widget\Widget;
use Elmphant\Tui\Widget\WidgetContainer;

/**
 * @internal the border a Panel or a Box lights while a bound component somewhere inside holds the keyboard
 */
final class KeyboardInside
{
    public static function of(Widget $widget, ?ComponentId $focused): bool
    {
        if ($focused === null || !$widget instanceof WidgetContainer) {
            return false;
        }

        foreach ($widget->children() as $child) {
            if ($child instanceof SingleMount) {
                $mounted = ComponentId::named($child->mountName());
                if ($focused->equals($mounted) || $focused->isUnder($mounted)) {
                    return true;
                }
            }
            if (self::of($child, $focused)) {
                return true;
            }
        }

        return false;
    }
}
