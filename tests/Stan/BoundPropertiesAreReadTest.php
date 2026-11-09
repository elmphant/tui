<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Stan;

use Elmphant\Tui\Stan\BoundPropertiesAreRead;
use Elmphant\Tui\Tests\Stan\Fixtures\BoundPropertiesApp;
use Elmphant\Tui\Tests\Stan\Fixtures\BoundPropertiesModel;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Testing\PHPStanTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

final class BoundPropertiesAreReadTest extends PHPStanTestCase
{
    #[Test]
    #[DataProvider('properties')]
    public function aPropertyIsReadOnlyWhenItsClassBindsOrMountsItByName(string $className, string $property, bool $read): void
    {
        $container = self::getContainer();
        $class = $container->getByType(ReflectionProvider::class)->getClass($className);

        self::assertSame($read, $container->getByType(BoundPropertiesAreRead::class)->isAlwaysRead($class->getNativeProperty($property), $property));
    }

    /**
     * @return iterable<string, array{class-string, string, bool}>
     */
    public static function properties(): iterable
    {
        yield 'bound' => [BoundPropertiesApp::class, 'name', true];
        yield 'mounted' => [BoundPropertiesApp::class, 'counter', true];
        yield 'forgotten' => [BoundPropertiesApp::class, 'forgotten', false];
        yield 'bound by a model that is no app, through a constant' => [BoundPropertiesModel::class, 'query', true];
        yield 'forgotten by a model that is no app' => [BoundPropertiesModel::class, 'forgotten', false];
    }

    /**
     * @return list<string>
     */
    public static function getAdditionalConfigFiles(): array
    {
        return [dirname(__DIR__, 2) . '/rules.neon'];
    }
}
