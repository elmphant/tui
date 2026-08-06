<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Core\Support;

use Elmphant\Tui\Core\App;
use Elmphant\Tui\Core\Component;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Widget\Interactive;
use LogicException;

/**
 * @internal test-fixture shorthand for fixtures whose id differs from its property
 */
final class TestComponents
{
    /**
     * @return Component<Interactive>
     */
    public static function bind(string $id, string $property, Interactive $widget): Component
    {
        return Component::bind(
            ComponentId::named($id),
            $widget,
            static fn (App $current, Interactive $updated): App => self::replace($current, $property, $updated),
        );
    }

    private static function replace(App $app, string $property, Interactive $widget): CopyableTestApp
    {
        if (!$app instanceof CopyableTestApp) {
            throw new LogicException('Test component replacement requires a CopyableTestApp');
        }

        return $app->cloneWith(...[$property => $widget]);
    }
}
