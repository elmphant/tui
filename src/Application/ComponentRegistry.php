<?php

declare(strict_types=1);

namespace Elmphant\Tui\Application;

use Closure;
use Elmphant\Tui\Core\ComponentId;
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
     * @param list<Mount<*>>             $mounts
     * @param list<Mount<*>>             $listening  the mounts input can reach: none behind an open modal
     */
    private function __construct(
        private array $components,
        private array $byId,
        private array $focusables,
        private array $scopes = [],
        private array $mounts = [],
        private array $listening = [],
    ) {
    }

    /**
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

        return new self(
            components: $registry->components,
            byId: $registry->byId,
            focusables: $registry->focusables,
            scopes: $registry->scopes,
            mounts: self::mountsIn($root),
            listening: self::listeningMountsIn($root)[0],
        );
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
     * @return list<Mount<*>>
     */
    public function mounts(): array
    {
        return $this->mounts;
    }

    /**
     * The mounts that keys and the pointer can reach. A mount behind an open modal
     * is not one of them.
     *
     * @return list<Mount<*>>
     */
    public function listeningMounts(): array
    {
        return $this->listening;
    }

    /**
     * @return list<Component<Interactive>>
     */
    public function focusables(): array
    {
        return $this->focusables;
    }

    /**
     * Tab stays within the active focus scope and skips other scopes, including nested ones.
     * Enter or leave a scope with a focus command.
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

        // If a scope is the only content, keep its stops reachable so focus has somewhere to land.
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
            fn (Component $candidate): bool => !$candidate->id->equals($id)
                && (self::holdsComponent($candidate, $id) || $this->holdsMountedComponent($candidate, $id)),
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
     * These IDs identify a container's own control, such as a tab strip, rather than its content.
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
        // If a focus stop left the tree, use the scope of its former pane.
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
                listening: $this->listening,
            );
    }

    /**
     * List nested scopes first so the innermost scope handles a component shared by several scopes.
     *
     * @return list<list<string>>
     */
    private static function scopesIn(Widget $widget): array
    {
        $scopes = [];
        // A bound scope exposes its stops through its component, so treat the component as the scope.
        $self = $widget instanceof Component && $widget->widget instanceof FocusScope ? $widget->widget : $widget;

        if ($self instanceof WidgetContainer) {
            foreach ($self->children() as $child) {
                array_push($scopes, ...self::scopesIn($child));
            }
        }

        if ($self instanceof Mount) {
            $scopes = array_map(
                static fn (array $ids): array => array_map(static fn (string $id): string => $self->id->value . '/' . $id, $ids),
                $scopes,
            );
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
     * @return list<Mount<*>>
     */
    private static function mountsIn(Widget $widget): array
    {
        if ($widget instanceof Mount) {
            return [$widget];
        }

        $mounts = [];

        if ($widget instanceof WidgetContainer) {
            foreach ($widget->children() as $child) {
                array_push($mounts, ...self::mountsIn($child));
            }
        }

        return $mounts;
    }

    /**
     * Drop mounts behind an input layer, just as collectLayer() drops components.
     *
     * @return array{list<Mount<*>>, bool}
     */
    private static function listeningMountsIn(Widget $widget): array
    {
        $mounts = [];
        $layer = null;

        if ($widget instanceof WidgetContainer) {
            foreach ($widget->children() as $child) {
                [$childMounts, $childIsLayered] = self::listeningMountsIn($child);
                if ($childIsLayered) {
                    $layer = $childMounts;
                }

                array_push($mounts, ...$childMounts);
            }
        }

        if ($widget instanceof Mount) {
            return [[$widget], $layer !== null];
        }

        return $layer === null ? [$mounts, $widget instanceof InputLayer && $widget->claimsInput()] : [$layer, true];
    }

    /**
     * @return list<Component<Interactive>>
     */
    private static function collect(Widget $widget): array
    {
        return self::collectLayer($widget)[0];
    }

    /**
     * The last input layer wins because it is drawn on top; discard components behind it.
     *
     * @return array{list<Component<Interactive>>, bool}
     */
    private static function collectLayer(Widget $widget, bool $bound = false): array
    {
        $components = [];
        $publishedIds = [];

        if ($widget instanceof Component) {
            /** @var Component<Interactive> $widget */
            array_push($components, ...self::published($widget));
            $publishedIds = array_map(static fn (Component $component): string => $component->id->value, $components);
        }

        $layerComponents = null;
        if ($widget instanceof WidgetContainer) {
            foreach ($widget->children() as $child) {
                [$childComponents, $childIsLayered] = self::collectLayer($child, $widget instanceof Component);
                $childComponents = array_values(array_filter($childComponents, static fn (Component $component): bool => !in_array($component->id->value, $publishedIds, true)));
                if ($childIsLayered) {
                    $layerComponents = $childComponents;
                }

                array_push($components, ...$childComponents);
            }
        }

        if ($layerComponents !== null) {
            return [self::reowned($widget, [...self::ownComponent($widget), ...$layerComponents]), true];
        }

        $claims = $widget instanceof InputLayer && $widget->claimsInput();
        if ($claims && !$bound && $components !== []) {
            throw new LogicException(sprintf('%s holds the bound component "%s" but is not bound itself, so it never hears the keys and clicks that component leaves alone: wrap it in Component::bind()', get_debug_type($widget), $components[0]->id->value));
        }

        return [self::reowned($widget, $components), $claims];
    }

    /**
     * Mounted components write back to their sub-model, which created their bindings.
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
     * Keep containers available for clicks, broadcasts and unhandled child keys, even when tab navigation skips them.
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

        return [$component, ...self::publishedBelow($component, $widget, [])];
    }

    /**
     * A passive container (a Layout, a Box) is no stop itself, so its interactive children are published through it.
     *
     * @param Component<Interactive> $component
     * @param list<int>              $path
     *
     * @return list<Component<Interactive>>
     */
    private static function publishedBelow(Component $component, GraftsItsChildren $container, array $path): array
    {
        $stops = [];
        $index = 0;

        foreach ($container->children() as $child) {
            if ($child instanceof Interactive) {
                array_push($stops, ...self::published($component->childThrough([...$path, $index], $child)));
            } elseif ($child instanceof Component && !$child->writesBack() && $child->widget instanceof Interactive) {
                /** @var Component<Interactive> $child */
                array_push($stops, ...self::published($component->childThrough([...$path, $index], $child->widget, $child)));
            } elseif ($child instanceof GraftsItsChildren && !$child instanceof Component) {
                array_push($stops, ...self::publishedBelow($component, $child, [...$path, $index]));
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
     * A mount qualifies the ids of the components it registers, but the widgets inside them still
     * carry the ids their model gave them. So a component inside a mount looks for the local id.
     *
     * @param Component<Interactive> $candidate
     */
    private function holdsMountedComponent(Component $candidate, ComponentId $id): bool
    {
        $mount = null;
        foreach ($this->mounts as $each) {
            if ($candidate->id->isUnder($each->id) && $id->isUnder($each->id) && \strlen($each->id->value) > \strlen($mount->id->value ?? '')) {
                $mount = $each;
            }
        }

        return $mount !== null && self::holdsComponent($candidate->widget, ComponentId::named(substr($id->value, \strlen($mount->id->value) + 1)));
    }

    private static function holdsComponent(Widget $widget, ComponentId $id): bool
    {
        if ($widget instanceof Component && ($widget->id->equals($id) || $id->isUnder($widget->id))) {
            return true;
        }

        if ($widget instanceof Mount && $id->isUnder($widget->id)) {
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
        if (!$widget instanceof Component) {
            return [];
        }

        $layer = $widget->widget;

        /** @var list<Component<Interactive>> */
        return $layer instanceof InputLayer && $layer->claimsInput() ? self::published($widget) : [$widget];
    }
}
