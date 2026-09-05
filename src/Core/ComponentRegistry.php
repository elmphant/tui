<?php

declare(strict_types=1);

namespace Elmphant\Tui\Core;

use Closure;
use Elmphant\Tui\Widget\Focus\FocusScope;
use Elmphant\Tui\Widget\Focusable;
use Elmphant\Tui\Widget\GraftsItsChildren;
use Elmphant\Tui\Widget\Interactive;
use Elmphant\Tui\Widget\Navigates;
use Elmphant\Tui\Widget\Navigation;
use Elmphant\Tui\Widget\Popup\InputLayer;
use Elmphant\Tui\Widget\Widget;
use Elmphant\Tui\Widget\WidgetContainer;
use LogicException;
use WeakMap;

/**
 * @internal validated, indexed component bindings for one App value
 */
final readonly class ComponentRegistry
{
    /**
     * @param list<Component<Interactive>>          $components
     * @param array<string, Component<Interactive>> $byId
     * @param list<Component<Interactive>>          $focusables
     * @param list<list<string>>                    $scopes
     */
    private function __construct(
        private array $components,
        private array $byId,
        private array $focusables,
        private array $scopes = [],
    ) {
    }

    /**
     * Memoize bindings for one immutable App value.
     *
     * @param Closure(): Widget $view
     */
    public static function for(object $owner, Closure $view): self
    {
        /** @var WeakMap<object, self>|null $registries */
        static $registries = null;
        $registries ??= new WeakMap();

        return $registries[$owner] ??= self::fromWidget($view());
    }

    public static function fromWidget(Widget $root): self
    {
        return self::from(self::collect($root))->withScopes(self::scopesIn($root));
    }

    /**
     * @param array<array-key, mixed> $components
     */
    public static function from(array $components): self
    {
        $validated = [];
        $byId = [];
        $focusables = [];

        foreach ($components as $index => $component) {
            if (!$component instanceof Component) {
                throw new LogicException(sprintf('Component collection entry %s must be a %s', $index, Component::class));
            }
            if (isset($byId[$component->id->value])) {
                throw new LogicException(sprintf('Duplicate component ID "%s"', $component->id->value));
            }

            $validated[] = $component;
            $byId[$component->id->value] = $component;
            if (self::isSomewhereTheKeyboardCanGo($component)) {
                $focusables[] = $component;
            }
        }

        return new self($validated, $byId, $focusables);
    }

    /**
     * @return list<Component<Interactive>>
     */
    public function all(): array
    {
        return $this->components;
    }

    /**
     * @return list<Component<Interactive>>
     */
    public function focusables(): array
    {
        return $this->focusables;
    }

    /**
     * The ring the keyboard is walking. A focus scope holding it keeps tab to its
     * own controls; from anywhere else those controls are off the ring altogether,
     * so the way in is the command that says so and tab never walks chrome on its
     * way between the panes a reader works in.
     *
     * @return list<Component<Interactive>>
     */
    public function ring(?ComponentId $focused): array
    {
        $walkable = array_values(array_filter(
            $this->focusables,
            static fn (Component $component): bool => self::navigationOf($component->widget) !== Navigation::Pointer,
        ));

        if ($this->scopes === []) {
            return $walkable;
        }

        $held = $this->scopeHolding($focused);

        if ($held !== null) {
            return array_values(array_filter(
                $walkable,
                static fn (Component $component): bool => \in_array($component->id->value, $held, true),
            ));
        }

        $beside = array_values(array_filter(
            $walkable,
            fn (Component $component): bool => !$this->isScoped($component->id->value),
        ));

        // Taking the chrome off the ring assumes there is content beside it. Where a
        // scope is all there is, an empty ring would leave the keyboard nowhere to
        // land and every key falling through.
        return $beside === [] ? $walkable : $beside;
    }

    /**
     * @return Component<Interactive>|null
     */
    public function get(ComponentId $id): ?Component
    {
        return $this->byId[$id->value] ?? null;
    }

    /**
     * Focus landing on one of these exactly is the reader on its own control — a
     * tab strip — rather than adrift in everything it draws.
     *
     * @return list<string>
     */
    public function stopPublishers(): array
    {
        $publishers = [];

        foreach ($this->components as $component) {
            foreach ($this->components as $candidate) {
                if ($candidate->id->isUnder($component->id)) {
                    $publishers[] = $component->id->value;

                    break;
                }
            }
        }

        return $publishers;
    }

    /**
     * @return list<string>|null
     */
    private function scopeHolding(?ComponentId $focused): ?array
    {
        if ($focused === null) {
            return null;
        }

        foreach ($this->scopes as $scope) {
            if (\in_array($focused->value, $scope, true)) {
                return $scope;
            }
        }

        return null;
    }

    private function isScoped(string $id): bool
    {
        foreach ($this->scopes as $scope) {
            if (\in_array($id, $scope, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<list<string>> $scopes
     */
    private function withScopes(array $scopes): self
    {
        return $scopes === []
            ? $this
            : new self(
                components: $this->components,
                byId: $this->byId,
                focusables: $this->focusables,
                scopes: $scopes,
            );
    }

    /**
     * The component ids under each focus scope in the tree, innermost first, so a
     * scope nested in another is the one that answers for a component in both.
     *
     * @return list<list<string>>
     */
    private static function scopesIn(Widget $widget): array
    {
        $scopes = [];

        if ($widget instanceof WidgetContainer) {
            foreach ($widget->children() as $child) {
                array_push($scopes, ...self::scopesIn($child));
            }
        }

        if ($widget instanceof FocusScope) {
            $scopes[] = array_map(
                static fn (Component $component): string => $component->id->value,
                self::collect($widget),
            );
        }

        return $scopes;
    }

    /**
     * @return list<Component<Interactive>>
     */
    private static function collect(Widget $widget): array
    {
        return self::collectLayer($widget)[0];
    }

    /**
     * Components of a widget, and whether they sit in an input layer.
     *
     * A layer takes input for itself: once one is found, the components behind
     * it are dropped, so an open modal cannot be tabbed or clicked past. The
     * last layer in the tree wins — it is the one drawn on top.
     *
     * @return array{list<Component<Interactive>>, bool}
     */
    private static function collectLayer(Widget $widget): array
    {
        $components = [];

        if ($widget instanceof Component) {
            /** @var Component<Interactive> $widget */
            array_push($components, ...self::published($widget));
        }

        $layerComponents = null;
        if ($widget instanceof WidgetContainer) {
            foreach ($widget->children() as $child) {
                [$childComponents, $childIsLayered] = self::collectLayer($child);
                if ($childIsLayered) {
                    $layerComponents = $childComponents;
                }

                array_push($components, ...$childComponents);
            }
        }

        if ($layerComponents !== null) {
            return [self::reowned($widget, [...self::ownComponent($widget), ...$layerComponents]), true];
        }

        return [self::reowned($widget, $components), $widget instanceof InputLayer];
    }

    /**
     * Components found under a mount belong to what it mounted, not to the App:
     * the sub-model bound them, so it is the one a replacement has to reach.
     *
     * @param list<Component<Interactive>> $components
     *
     * @return list<Component<Interactive>>
     */
    private static function reowned(Widget $widget, array $components): array
    {
        if (!$widget instanceof Mount) {
            return $components;
        }

        return array_map(
            $widget->own(...),
            $components,
        );
    }

    /**
     * The container stays in the list whatever `navigation()` answers: the pointer,
     * a broadcast and a key its child leaves alone still reach it; `from()` keeps a
     * `Never` frame off the ring.
     *
     * @param Component<Interactive> $component
     *
     * @return list<Component<Interactive>>
     */
    private static function published(Component $component): array
    {
        $widget = $component->widget;

        if (!$widget instanceof GraftsItsChildren) {
            return [$component];
        }

        $stops = [$component];
        $index = 0;

        foreach ($widget->children() as $child) {
            if ($child instanceof Interactive && self::navigationOf($child) !== Navigation::Never) {
                array_push($stops, ...self::published($component->childAt($index, $child)));
            }

            ++$index;
        }

        return $stops;
    }

    /**
     * @param Component<Widget> $component
     */
    private static function isSomewhereTheKeyboardCanGo(Component $component): bool
    {
        if (!$component->writesBack()) {
            return true;
        }

        return $component->widget instanceof Focusable
            && self::navigationOf($component->widget) !== Navigation::Never;
    }

    private static function navigationOf(Widget $widget): Navigation
    {
        return $widget instanceof Navigates ? $widget->navigation() : Navigation::Regular;
    }

    /**
     * @return list<Component<Interactive>>
     */
    private static function ownComponent(Widget $widget): array
    {
        /** @var list<Component<Interactive>> */
        return $widget instanceof Component ? [$widget] : [];
    }
}
