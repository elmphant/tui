<?php

declare(strict_types=1);

namespace Elmphant\Tui\Core;

use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Widget\Focusable;
use Elmphant\Tui\Widget\Interactive;

/**
 * What a press does to the keyboard, as rules about messages and commands
 * rather than about the app holding them.
 *
 * @internal
 */
final class PointerFocus
{
    /**
     * Whether anything that answered the pointer already said where the keyboard
     * goes. Where the pointer landed is only the default, so a more specific
     * answer to the same press must not be raced by it.
     */
    public static function alreadyMoved(?Cmd $command): bool
    {
        if ($command instanceof FocusCmd) {
            return true;
        }

        if ($command instanceof MappedCmd) {
            return self::alreadyMoved($command->inner);
        }

        if (!$command instanceof BatchCmd) {
            return false;
        }

        foreach ($command->commands as $child) {
            if (self::alreadyMoved($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Where the keyboard goes once the pointer has landed. A press on nothing
     * takes it away; a wheel turn over nothing leaves it where it was.
     */
    public static function after(MouseMsg $message, ?ComponentId $landedOn): ?Cmd
    {
        if ($landedOn !== null) {
            return Cmd::focus($landedOn);
        }

        return $message->isClick() ? Cmd::focusNothing() : null;
    }

    /**
     * The components with the one holding the keyboard marked, so a press is
     * answered by a tree that agrees with where the keys are going. Only handed
     * to a widget that does not already hold it: taking focus is how a pane is
     * told to seat its cursor, and marking one that never let go would walk the
     * reader back to its first child.
     *
     * @param list<Component<Interactive>> $components
     *
     * @return list<Component<Interactive>>
     */
    public static function told(array $components, ?ComponentId $holding): array
    {
        if ($holding === null) {
            return $components;
        }

        return array_map(
            static function (Component $component) use ($holding): Component {
                $widget = $component->widget;

                return $component->id->equals($holding) && $widget instanceof Focusable && !$widget->isFocused()
                    ? $component->withWidget($widget->withFocus(true))
                    : $component;
            },
            $components,
        );
    }
}
