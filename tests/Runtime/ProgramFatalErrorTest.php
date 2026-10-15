<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProgramFatalErrorTest extends TestCase
{
    #[Test]
    public function aFatalErrorStillStopsTheProcessesTheProgramStarted(): void
    {
        $seconds = sprintf('4242.%d', random_int(100000, 999999));

        exec(sprintf(
            '%s -d memory_limit=32M %s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(__DIR__ . '/Fixtures/fatal-with-running-process.php'),
            escapeshellarg($seconds),
        ), $output, $status);
        exec('pgrep -f ' . escapeshellarg('^sleep ' . $seconds), $survivors);
        exec('pkill -f ' . escapeshellarg('^sleep ' . $seconds));

        self::assertStringContainsString('Allowed memory size', implode("\n", $output));
        self::assertSame([], $survivors);
    }
}
