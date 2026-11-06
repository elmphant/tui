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
 * Verify that replacement callbacks store updated widgets where the tree can find them.
 *
 * @internal
 */
final class ComponentWriteBack
{
    /**
     * If a container update removes a child, discard that child's update instead of overwriting its replacement.
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
     * Merge child updates into the updated container so neither update is lost.
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
            $grafted = self::grafted($ancestorWidget, $path, $widget);
            assert($grafted instanceof Interactive);
            $byId[$ancestor] = [$ancestorComponent, $grafted];
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
             * An unchanged widget means the update was dropped; any other result may include valid derived changes.
             */
            if (self::asAuthored($rebound->widget) == self::asAuthored($original->widget)
                && self::asAuthored($widget) != self::asAuthored($original->widget)
            ) {
                throw new LogicException(sprintf('Component "%s" changed, but view() still draws it as before. Store the widget where view() reads it. If view() builds it fresh each time, keep that state in the app, or answer an outcome from Committing::outcomeOf() and handle it with on()', $original->id->value));
            }
        }
    }

    /**
     * @param list<int> $path
     */
    public static function grafted(Widget $container, array $path, Widget $child): Widget
    {
        if ($path === []) {
            return $child;
        }

        if (!$container instanceof GraftsItsChildren) {
            throw new LogicException(sprintf('%s published a child but does not graft one back', $container::class));
        }

        $index = $path[0];
        $inner = [...$container->children()][$index] ?? null;

        if ($inner === null) {
            throw new LogicException(sprintf('%s has no child at %d to graft into', $container::class, $index));
        }

        return $container->withChildAt($index, self::grafted($inner, array_slice($path, 1), $child));
    }

    /**
     * Remove routing state before comparing: models may rebuild widgets without focus or global bindings.
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
}
