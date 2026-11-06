<?php

declare(strict_types=1);

namespace Elmphant\Tui\Core\Cmd;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\ComponentId;

/**
 * @internal build one with Cmd::flash()
 */
final readonly class FlashCmd extends Cmd
{
    public function __construct(
        public ComponentId $component,
        public string $key,
        public float $seconds,
    ) {
    }

    /**
     * A component named inside a mount is named under the mount for the runtime.
     */
    public function within(?string $scope): self
    {
        return $scope === null ? $this : new self(
            component: ComponentId::named($scope . '/' . $this->component->value),
            key: $this->key,
            seconds: $this->seconds,
        );
    }
}
