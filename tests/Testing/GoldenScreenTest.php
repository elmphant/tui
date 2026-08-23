<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Testing;

use Elmphant\Tui\Testing\GoldenScreen;
use Elmphant\Tui\Testing\ScreenBuffer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\IncompleteTestError;
use PHPUnit\Framework\TestCase;

final class GoldenScreenTest extends TestCase
{
    #[Test]
    public function captureProducesPlainText(): void
    {
        $screen = new ScreenBuffer("\x1b[1;1HHello\x1b[2;1HWorld", 80, 24);
        $captured = GoldenScreen::capture($screen);
        $this->assertStringContainsString('Hello', $captured);
        $this->assertStringContainsString('World', $captured);
    }

    #[Test]
    public function snapshotMatchesOnSecondRun(): void
    {
        $dir = sys_get_temp_dir() . '/golden_test_' . uniqid();
        mkdir($dir);

        try {
            $screen = new ScreenBuffer("\x1b[1;1HSame", 80, 24);

            try {
                GoldenScreen::assertMatchesSnapshot('match', $screen, $dir);
            } catch (IncompleteTestError) {
                // first run creates snapshot
            }

            // Second run: should pass silently
            GoldenScreen::assertMatchesSnapshot('match', $screen, $dir);
            $this->assertTrue(true);
        } finally {
            if (is_file($dir . '/match.txt')) {
                unlink($dir . '/match.txt');
            }
            rmdir($dir);
        }
    }

    #[Test]
    public function snapshotCreatesFileOnFirstRun(): void
    {
        $dir = sys_get_temp_dir() . '/golden_test_' . uniqid();
        mkdir($dir);

        try {
            $screen = new ScreenBuffer("\x1b[1;1HTest", 80, 24);
            $path = $dir . '/test_create.txt';

            $this->assertFileDoesNotExist($path);

            try {
                GoldenScreen::assertMatchesSnapshot('test_create', $screen, $dir);
                $this->fail('Expected first run to throw IncompleteTestError');
            } catch (IncompleteTestError) {
                // expected on first run
            }

            $this->assertFileExists($path);
            $this->assertStringContainsString('Test', file_get_contents($path));
        } finally {
            if (is_file($dir . '/test_create.txt')) {
                unlink($dir . '/test_create.txt');
            }
            rmdir($dir);
        }
    }

    #[Test]
    public function snapshotDirTrailingSlashIsTrimmedFromTheSnapshotPath(): void
    {
        $dir = sys_get_temp_dir() . '/golden_test_' . uniqid();
        mkdir($dir);

        try {
            $screen = new ScreenBuffer("\x1b[1;1HTrim", 80, 24);

            try {
                GoldenScreen::assertMatchesSnapshot('trim', $screen, $dir . '/');
                $this->fail('Expected first run to mark the test incomplete');
            } catch (IncompleteTestError $error) {
                $this->assertSame(
                    "Snapshot created: {$dir}/trim.txt. Run again to verify.",
                    $error->getMessage(),
                );
            }
        } finally {
            if (is_file($dir . '/trim.txt')) {
                unlink($dir . '/trim.txt');
            }
            rmdir($dir);
        }
    }

    #[Test]
    public function snapshotMismatchFailsWithAGoldenScreenMessage(): void
    {
        $dir = sys_get_temp_dir() . '/golden_test_' . uniqid();
        mkdir($dir);
        file_put_contents($dir . '/mismatch.txt', 'stored snapshot content');

        try {
            $screen = new ScreenBuffer("\x1b[1;1HActual", 80, 24);

            try {
                GoldenScreen::assertMatchesSnapshot('mismatch', $screen, $dir);
                $this->fail('Expected the mismatching snapshot to fail the assertion');
            } catch (ExpectationFailedException $error) {
                $this->assertStringContainsString(
                    "Golden screen mismatch for 'mismatch'. Delete {$dir}/mismatch.txt to regenerate.",
                    $error->getMessage(),
                );
            }
        } finally {
            unlink($dir . '/mismatch.txt');
            rmdir($dir);
        }
    }
}
