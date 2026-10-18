<?php

declare(strict_types=1);

namespace Elmphant\Tui\Application;

use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Input\KeybindingsAware;
use Elmphant\Tui\Widget\Focus\FollowsTheKeyboard;
use Elmphant\Tui\Widget\Focus\GraftsItsChildren;
use Elmphant\Tui\Widget\Interactive;
use Elmphant\Tui\Widget\Widget;
use LogicException;

/**
 * How a batch of component updates reaches the model, and whether the model
 * stored each one where it said it would.
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
     * A container's own answer may let go of a child that answered too (a tab
     * switched pages); grafting into whatever sits there now would overwrite a
     * stranger, so the container's answer wins.
     *
     * @param array<array-key, array{Component<Interactive>, Interactive}> $updates
     *
     * @return array<array-key, array{Component<Interactive>, Interactive}>
     */
    public static function withoutLetGo(array $updates): array
    {
        $byId = self::byId($updates);

        $letGo = [];
        foreach ($byId as [$component]) {
            $ancestor = self::nearestAnswering($component->id, $byId);
            if ($ancestor === null) {
                continue;
            }

            [$ancestorComponent, $ancestorWidget] = $byId[$ancestor];
            if (self::heldAt($ancestorWidget, $component->id->pathBelow($ancestorComponent->id)) !== $component->widget) {
                $letGo[] = $component->id;
            }
        }

        return array_filter($updates, static function (array $update) use ($letGo): bool {
            foreach ($letGo as $gone) {
                if ($update[0]->id->equals($gone) || $update[0]->id->isUnder($gone)) {
                    return false;
                }
            }

            return true;
        });
    }

    /**
     * A grafted child writes back through its container as it was when the tree
     * was collected, so beside the container's own answer one of the two is lost.
     *
     * @param array<array-key, array{Component<Interactive>, Interactive}> $updates
     *
     * @return list<array{Component<Interactive>, Interactive}>
     */
    public static function foldedIntoAncestors(array $updates): array
    {
        $byId = self::byId($updates);

        foreach ($byId as $id => [$component, $widget]) {
            $ancestor = self::nearestAnswering($component->id, $byId);

            if ($ancestor === null) {
                continue;
            }

            [$ancestorComponent, $ancestorWidget] = $byId[$ancestor];
            $path = $component->id->pathBelow($ancestorComponent->id);
            $byId[$ancestor] = [$ancestorComponent, self::grafted($ancestorWidget, $path, $widget)];
            unset($byId[$id]);
        }

        return array_values($byId);
    }

    /**
     * @param Component<Interactive> $component
     */
    public static function verifyStored(Component $component, Interactive $widget, App $before, App $after): void
    {
        if ($after === $before && self::asAuthored($widget) != self::asAuthored($component->widget)) {
            throw new LogicException(sprintf('Component "%s" had nowhere to store its update: its replacement gave the model back unchanged', $component->id->value));
        }
    }

    /**
     * @param array<array-key, array{Component<Interactive>, Interactive}> $updates
     */
    public static function verify(ComponentRegistry $registry, array $updates): void
    {
        foreach ($updates as [$original, $widget]) {
            $rebound = $registry->get($original->id);

            if ($rebound === null) {
                continue;
            }

            /*
             * A dropped update leaves the widget as it was before the key, so
             * that is what this checks for. Any other widget means the model kept
             * the change and derived something else from it too — a title, a
             * hint — which is allowed.
             */
            if (self::asAuthored($rebound->widget) == self::asAuthored($original->widget)
                && self::asAuthored($widget) != self::asAuthored($original->widget)
            ) {
                throw new LogicException(sprintf('Component "%s" stored its update on another property', $original->id->value));
            }
        }
    }

    /**
     * The widget without what routing put on it on the way in — focus, where the
     * keyboard arrived, and the app's global bindings. A model that lets the tree
     * own those rebuilds the widget without them, and would otherwise never
     * compare equal.
     */
    private static function asAuthored(Interactive $widget): Interactive
    {
        if ($widget instanceof FollowsTheKeyboard) {
            $widget = $widget->withKeyboardAt(null);
        }

        return $widget instanceof KeybindingsAware
            ? $widget->withGlobalKeybindings(null)
            : $widget;
    }

    /**
     * @param array<array-key, array{Component<Interactive>, Interactive}> $updates
     *
     * @return array<string, array{Component<Interactive>, Interactive}>
     */
    private static function byId(array $updates): array
    {
        $byId = [];
        foreach ($updates as [$component, $widget]) {
            $byId[$component->id->value] = [$component, $widget];
        }

        return $byId;
    }

    /**
     * @param array<string, array{Component<Interactive>, Interactive}> $byId
     */
    private static function nearestAnswering(ComponentId $id, array $byId): ?string
    {
        $nearest = null;

        foreach ($byId as $candidate => [$component]) {
            if ($id->isUnder($component->id) && ($nearest === null || strlen($candidate) > strlen($nearest))) {
                $nearest = $candidate;
            }
        }

        return $nearest;
    }

    /**
     * @param list<int> $path
     */
    private static function heldAt(Widget $container, array $path): ?Widget
    {
        foreach ($path as $index) {
            if (!$container instanceof GraftsItsChildren) {
                return null;
            }

            $container = [...$container->children()][$index] ?? null;
            if ($container === null) {
                return null;
            }
        }

        return $container;
    }

    /**
     * @param non-empty-list<int> $path
     */
    private static function grafted(Interactive $container, array $path, Interactive $child): Interactive
    {
        if (!$container instanceof GraftsItsChildren) {
            throw new LogicException(sprintf('%s published a child but does not graft one back', $container::class));
        }

        $index = $path[0];
        $rest = array_slice($path, 1);

        if ($rest === []) {
            return $container->withChildAt($index, $child);
        }

        $inner = [...$container->children()][$index] ?? null;

        if (!$inner instanceof Interactive) {
            throw new LogicException(sprintf('%s has no interactive child at %d to graft into', $container::class, $index));
        }

        return $container->withChildAt($index, self::grafted($inner, $rest, $child));
    }
}
