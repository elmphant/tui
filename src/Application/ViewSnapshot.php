<?php

declare(strict_types=1);

namespace Elmphant\Tui\Application;

use Closure;
use Elmphant\Tui\Widget\Widget;
use WeakMap;

/**
 * Share one cached tree across rendering, layout, discovery and routing; WeakMap releases it with its App.
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
