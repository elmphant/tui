<?php

declare(strict_types=1);

namespace Elmphant\Tui\Testing;

final class GoldenScreen
{
    public static function capture(ScreenBuffer $screen): string
    {
        return $screen->plainText();
    }

    public static function assertMatchesSnapshot(string $name, ScreenBuffer $screen, string $snapshotDir): void
    {
        $path = rtrim($snapshotDir, '/') . '/' . $name . '.txt';
        $captured = self::capture($screen);

        if (!file_exists($path)) {
            file_put_contents($path, $captured);
            \PHPUnit\Framework\Assert::markTestIncomplete("Snapshot created: {$path}. Run again to verify.");
        }

        $expected = file_get_contents($path);
        \PHPUnit\Framework\Assert::assertSame($expected, $captured, "Golden screen mismatch for '{$name}'. Delete {$path} to regenerate.");
    }
}
