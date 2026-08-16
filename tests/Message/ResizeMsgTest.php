<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Message;

use Elmphant\Tui\Message\ResizeMsg;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ResizeMsgTest extends TestCase
{
    #[Test]
    public function aNegativeSizeIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ResizeMsg(-5, 20);
    }
}
