<?php

declare(strict_types=1);

namespace Elmphant\Tui\Core;

use Closure;
use Elmphant\Tui\Widget\Widget;
use WeakMap;

/**
 * Memoizes the pure view tree for one immutable App value.
 *
 * The same node objects are shared by rendering, layout resolution, component
 * discovery, focus, and message routing. Entries die with their App via WeakMap.
 *
 * @internal
 */
final class ViewSnapshot
{
    /**
     * @var WeakMap<object, Widget>|null
     */
    private static ?WeakMap $entries = null;

    /**
     * @param Closure(): Widget $view
     */
    public static function for(object $owner, Closure $view): Widget
    {
        $entries = self::$entries ??= new WeakMap();

        return $entries[$owner] ??= $view();
    }
}
