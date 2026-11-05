<?php

declare(strict_types=1);

namespace Elmphant\Tui\Application;

use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\Reducer;
use Elmphant\Tui\Widget\Interactive;
use Elmphant\Tui\Widget\Widget;
use LogicException;

/**
 * For a readonly model using Cloneable: bind the widget a property holds, with the write-back generated.
 */
trait BindsProperties
{
    /**
     * With `at:`, the property is an array and the widget the one under that key, written back there with the id `property.key`.
     *
     * @return Component<Interactive>
     */
    protected function bind(string $property, ComponentId|string|null $id = null, int|string|null $at = null): Component
    {
        $held = $this->valueOf($property);
        $widget = $at === null ? $held : (is_array($held) ? $held[$at] ?? null : null);
        if (!$widget instanceof Interactive) {
            $where = $at === null ? '' : sprintf('[%s]', var_export($at, true));
            throw new LogicException(sprintf('%s::$%s%s holds %s, and only an Interactive widget can be bound', static::class, $property, $where, get_debug_type($widget)));
        }

        if ($at === null) {
            return Component::bind($id ?? $property, $widget, static fn (self $owner, Interactive $updated): self => $owner->cloneWith(...[$property => $updated]));
        }

        return Component::bind(
            $id ?? $property . '.' . $at,
            $widget,
            static fn (self $owner, Interactive $updated): self => $owner->cloneWith(...[$property => array_replace((array)$owner->valueOf($property), [$at => $updated])]),
        );
    }

    /**
     * Mount the sub-model a property holds (with `at:`, the one under that key of an array, named `property.key`), drawn as $view and stored back there.
     *
     * @return Mount<Reducer>
     */
    protected function mount(string $property, Widget $view, ComponentId|string|null $id = null, int|string|null $at = null): Mount
    {
        $held = $this->valueOf($property);
        $model = $at === null ? $held : (is_array($held) ? $held[$at] ?? null : null);
        if (!$model instanceof Reducer) {
            $where = $at === null ? '' : sprintf('[%s]', var_export($at, true));
            throw new LogicException(sprintf('%s::$%s%s holds %s, and only a Reducer can be mounted', static::class, $property, $where, get_debug_type($model)));
        }

        if ($at === null) {
            return Mount::named($id ?? $property, $model, $view, static fn (self $owner, Reducer $updated): self => $owner->cloneWith(...[$property => $updated]));
        }

        return Mount::named(
            $id ?? $property . '.' . $at,
            $model,
            $view,
            static fn (self $owner, Reducer $updated): self => $owner->cloneWith(...[$property => array_replace((array)$owner->valueOf($property), [$at => $updated])]),
        );
    }
}
