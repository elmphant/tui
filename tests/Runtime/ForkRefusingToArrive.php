<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use Elmphant\Tui\Core\Message;
use RuntimeException;

final class ForkRefusingToArrive implements Message
{
    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        throw new RuntimeException('cannot be rebuilt');
    }
}
