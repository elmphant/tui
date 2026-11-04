<?php

declare(strict_types=1);

namespace Elmphant\Tui\Application;

use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Widget\Interactive;
use LogicException;

/**
 * For a readonly model using Cloneable: bind the widget a property holds, with the write-back generated.
 */
trait BindsProperties
{
    /**
     * @return Component<Interactive>
     */
    protected function bind(string $property, ComponentId|string|null $id = null): Component
    {
        $widget = $this->valueOf($property);
        if (!$widget instanceof Interactive) {
            throw new LogicException(sprintf('%s::$%s holds %s, and only an Interactive widget can be bound', static::class, $property, get_debug_type($widget)));
        }

        return Component::bind(
            $id ?? $property,
            $widget,
            static fn (self $owner, Interactive $updated): self => $owner->cloneWith(...[$property => $updated]),
        );
    }
}
