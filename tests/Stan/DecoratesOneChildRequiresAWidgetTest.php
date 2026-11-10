<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Stan;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DecoratesOneChildRequiresAWidgetTest extends TestCase
{
    #[Test]
    public function onlyAWrapperThatIsNoWidgetIsReported(): void
    {
        $root = dirname(__DIR__, 2);
        $fixtures = __DIR__ . '/Fixtures';

        exec(
            sprintf(
                '%s %s analyse --no-progress --error-format=raw --configuration=%s %s %s 2>&1',
                escapeshellarg(PHP_BINARY),
                escapeshellarg($root . '/vendor/bin/phpstan'),
                escapeshellarg($fixtures . '/level-two.neon'),
                escapeshellarg($fixtures . '/WrapperThatIsAWidget.php'),
                escapeshellarg($fixtures . '/WrapperThatIsNoWidget.php'),
            ),
            $lines,
        );

        self::assertSame(
            [$fixtures . '/WrapperThatIsNoWidget.php:12:Trait Elmphant\Tui\Widget\DecoratesOneChild requires using class to implement Elmphant\Tui\Widget\Widget, but Elmphant\Tui\Tests\Stan\Fixtures\WrapperThatIsNoWidget does not. [identifier=class.missingImplements]'],
            array_values(array_filter($lines, static fn (string $line): bool => str_starts_with($line, $fixtures))),
        );
    }
}
