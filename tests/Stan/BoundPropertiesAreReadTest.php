<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Stan;

use Elmphant\Tui\Stan\BoundPropertiesAreRead;
use Elmphant\Tui\Tests\Stan\Fixtures\BoundPropertiesApp;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Testing\PHPStanTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

final class BoundPropertiesAreReadTest extends PHPStanTestCase
{
    #[Test]
    #[DataProvider('properties')]
    public function aPropertyIsReadOnlyWhenTheAppBindsOrMountsItByName(string $property, bool $read): void
    {
        $container = self::getContainer();
        $class = $container->getByType(ReflectionProvider::class)->getClass(BoundPropertiesApp::class);

        self::assertSame($read, $container->getByType(BoundPropertiesAreRead::class)->isAlwaysRead($class->getNativeProperty($property), $property));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function properties(): iterable
    {
        yield 'bound' => ['name', true];
        yield 'mounted' => ['counter', true];
        yield 'forgotten' => ['forgotten', false];
    }

    /**
     * @return list<string>
     */
    public static function getAdditionalConfigFiles(): array
    {
        return [dirname(__DIR__, 2) . '/rules.neon'];
    }
}
