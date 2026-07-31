<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Core\Support;

use Elmphant\Tui\Core\App;
use ReflectionObject;

/**
 * @internal keeps test fixtures compact; production App has no reflective copy API
 */
abstract readonly class CopyableTestApp extends App
{
    /**
     * @internal test-fixture keyed write-back primitive
     */
    public function cloneWith(mixed ...$overrides): static
    {
        $values = [];
        foreach ((new ReflectionObject($this))->getProperties() as $property) {
            $values[$property->getName()] = $property->getValue($this);
        }

        foreach ($overrides as $name => $value) {
            if (!array_key_exists($name, $values) || $values[$name] !== $value) {
                return new static(...[...$values, ...$overrides]);
            }
        }

        return $this;
    }
}
