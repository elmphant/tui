<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use Elmphant\Tui\Runtime\EffectOutputLimitExceeded;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EffectOutputLimitExceededTest extends TestCase
{
    #[Test]
    public function itDescribesTheEffectAndTheLimitInItsMessage(): void
    {
        $exception = new EffectOutputLimitExceeded(4096, 'Process');

        self::assertSame('Process output exceeded the configured 4096-byte limit', $exception->getMessage());
        self::assertSame(4096, $exception->limitBytes);
    }
}
