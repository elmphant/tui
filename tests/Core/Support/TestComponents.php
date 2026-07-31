<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Core\Support;

use Elmphant\Tui\Core\Component;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Widget\Interactive;
use LogicException;

/**
 * @internal test-fixture shorthand; production examples use typed closures
 */
final class TestComponents
{
    /**
     * @return Component<Interactive>
     */
    public static function bind(CopyableTestApp $app, string $id, string $property, Interactive $widget): Component
    {
        return Component::bind(
            ComponentId::named($id),
            $widget,
            static fn (\Elmphant\Tui\Core\App $current, Interactive $updated): \Elmphant\Tui\Core\App => self::replace($current, $property, $updated),
        );
    }

    private static function replace(\Elmphant\Tui\Core\App $app, string $property, Interactive $widget): CopyableTestApp
    {
        if (!$app instanceof CopyableTestApp) {
            throw new LogicException('Test component replacement requires a CopyableTestApp');
        }

        return $app->cloneWith(...[$property => $widget]);
    }
}
