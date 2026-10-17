<?php

declare(strict_types=1);

namespace Elmphant\Tui\Application;

use Closure;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\Reducer;
use Elmphant\Tui\Widget\Focus\FocusScope;
use Elmphant\Tui\Widget\Focus\GraftsItsChildren;
use Elmphant\Tui\Widget\Focus\InputLayer;
use Elmphant\Tui\Widget\Focus\Navigates;
use Elmphant\Tui\Widget\Focus\Navigation;
use Elmphant\Tui\Widget\Focusable;
use Elmphant\Tui\Widget\Interactive;
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
     * @param list<Mount<App, Reducer>>             $mounts
     */
    private function __construct(
        private array $components,
        private array $byId,
        private array $focusables,
        private array $scopes = [],
        private array $mounts = [],
    ) {
    }

    /**
     * Memoize bindings for one immutable App value.
     *
     * @param Closure(): Widget $view
     */
    public static function for(object $owner, Closure $view): self
    {
        static $registries = null;
        $registries ??= new WeakMap();

        /**
         * @var WeakMap<object, self> $registries
         */
        return $registries[$owner] ??= self::fromWidget($view());
    }

    public static function fromWidget(Widget $root): self
    {
        $registry = self::from(self::collect($root))->withScopes(self::scopesIn($root));

        return new self($registry->components, $registry->byId, $registry->focusables, $registry->scopes, self::mountsIn($root));
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
     * @return list<Mount<App, Reducer>>
     */
    public function mounts(): array
    {
        return $this->mounts;
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
     * way between the panes a reader works in. A scope nested in the held one is
     * off its ring by the same rule.
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
            $walkable = array_values(array_filter(
                $walkable,
                static fn (Component $component): bool => \in_array($component->id->value, $held, true),
            ));
        }

        $beside = array_values(array_filter(
            $walkable,
            fn (Component $component): bool => !$this->isScoped($component->id->value, $held),
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
     * @return Component<Interactive>|null
     */
    public function focusable(?ComponentId $id): ?Component
    {
        if ($id === null) {
            return null;
        }

        foreach ($this->focusables as $component) {
            if ($component->id->equals($id)) {
                return $component;
            }
        }

        return null;
    }

    /**
     * The components whose subtree holds $id, outermost first.
     *
     * @return list<Component<Interactive>>
     */
    public function enclosing(ComponentId $id): array
    {
        return array_values(array_filter(
            $this->components,
            static fn (Component $candidate): bool => !$candidate->id->equals($id) && self::holdsComponent($candidate, $id),
        ));
    }

    public static function childHolding(WidgetContainer $container, ComponentId $id): ?int
    {
        $index = 0;

        foreach ($container->children() as $child) {
            if (self::holdsComponent($child, $id)) {
                return $index;
            }

            ++$index;
        }

        return null;
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
        // A stop that just left the tree is in no scope; the pane it left is.
        for ($id = $focused; $id !== null; $id = $id->parent()) {
            foreach ($this->scopes as $scope) {
                if (\in_array($id->value, $scope, true)) {
                    return $scope;
                }
            }
        }

        return null;
    }

    /**
     * @param list<string>|null $within
     */
    private function isScoped(string $id, ?array $within): bool
    {
        foreach ($this->scopes as $scope) {
            $nested = $within === null || ($scope !== $within && array_diff($scope, $within) === []);

            if ($nested && \in_array($id, $scope, true)) {
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
                mounts: $this->mounts,
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
        // A bound scope publishes its stops through the component, so the component is the scope.
        $self = $widget instanceof Component && $widget->widget instanceof FocusScope ? $widget->widget : $widget;

        if ($self instanceof WidgetContainer) {
            foreach ($self->children() as $child) {
                array_push($scopes, ...self::scopesIn($child));
            }
        }

        if ($self instanceof FocusScope) {
            $scopes[] = array_map(
                static fn (Component $component): string => $component->id->value,
                self::collect($widget),
            );
        }

        return $scopes;
    }

    /**
     * @return list<Mount<App, Reducer>>
     */
    private static function mountsIn(Widget $widget): array
    {
        $mounts = $widget instanceof Mount ? [$widget] : [];

        if ($widget instanceof WidgetContainer) {
            foreach ($widget->children() as $child) {
                array_push($mounts, ...self::mountsIn($child));
            }
        }

        return $mounts;
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
            if ($child instanceof Interactive) {
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

    private static function holdsComponent(Widget $widget, ComponentId $id): bool
    {
        if ($widget instanceof Component && ($widget->id->equals($id) || $id->isUnder($widget->id))) {
            return true;
        }

        if (!$widget instanceof WidgetContainer) {
            return false;
        }

        foreach ($widget->children() as $child) {
            if (self::holdsComponent($child, $id)) {
                return true;
            }
        }

        return false;
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
