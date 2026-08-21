<?php

declare(strict_types=1);

namespace Elmphant\Tui\Core;

use Elmphant\Tui\Input\KeybindingsAware;
use Elmphant\Tui\Widget\Focusable;
use Elmphant\Tui\Widget\Interactive;
use LogicException;

/**
 * Whether a model stored a component's update where it said it would.
 *
 * A component is bound with a closure that writes the updated widget back onto
 * the model. Nothing makes that closure honest, so the update is applied and
 * then the tree is asked for the component again: what comes back has to be
 * what went in, or the closure put it somewhere the tree cannot see and the
 * next keystroke would act on the widget the reader already changed.
 *
 * @internal
 */
final class ComponentWriteBack
{
    /**
     * @param array<array-key, array{Component<Interactive>, Interactive}> $updates
     */
    public static function verify(ComponentRegistry $registry, array $updates): void
    {
        foreach ($updates as [$original, $widget]) {
            $rebound = $registry->get($original->id);

            if ($rebound === null) {
                throw new LogicException(sprintf('Component "%s" disappeared during an update', $original->id->value));
            }

            /*
             * Equal, not identical: a model may keep what the component changed
             * rather than the component, and build the widget back from it.
             */
            if (self::asAuthored($rebound->widget) != self::asAuthored($widget)) {
                throw new LogicException(sprintf('Component "%s" stored its update on another property', $original->id->value));
            }
        }
    }

    /**
     * The widget without what routing put on it on the way in — focus and the
     * app's global bindings. A model that lets the tree own those rebuilds the
     * widget without them, and would otherwise never compare equal.
     */
    private static function asAuthored(Interactive $widget): Interactive
    {
        if ($widget instanceof Focusable) {
            $widget = $widget->withFocus(false);
        }

        return $widget instanceof KeybindingsAware
            ? $widget->withGlobalKeybindings(null)
            : $widget;
    }
}
