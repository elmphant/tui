<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Terminal;

use Elmphant\Tui\Terminal\TerminalException;
use Elmphant\Tui\Terminal\TerminalWriteException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TerminalWriteExceptionTest extends TestCase
{
    #[Test]
    public function messageReportsWrittenAndTotalByteCounts(): void
    {
        $exception = new TerminalWriteException(3, 10);

        self::assertInstanceOf(TerminalException::class, $exception);
        self::assertSame(3, $exception->bytesWritten);
        self::assertFalse($exception->stalled, 'a terminal that went away has not merely stalled');
        self::assertSame('Terminal output stopped after 3 of 10 bytes', $exception->getMessage());
    }
}
