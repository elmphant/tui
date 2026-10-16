<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Api;

use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;
use SplFileInfo;

/**
 * API.md tells application code never to call or type against an `@internal`
 * declaration. A public signature that hands one back makes that impossible to
 * obey, so the boundary has to hold mechanically rather than by review.
 *
 * Public surface is what carries no `@internal` marker of its own and sits on a
 * class that carries none either.
 */
final class InternalTypeExposureTest extends TestCase
{
    #[Test]
    public function noPublicSignatureExposesAnInternalType(): void
    {
        $exposures = self::exposuresIn(self::declaredTypes());

        self::assertSame([], $exposures, "Public signatures must not hand out @internal types:\n" . implode("\n", $exposures));
    }

    #[Test]
    public function aConstantHoldingAnInternalValueAndAnIntersectionNamingOneAreExposures(): void
    {
        self::assertSame(
            [
                'PublicFixture::MODE exposes InternalModeFixture',
                'PublicFixture::paired() exposes InternalModeFixture',
            ],
            self::exposuresIn([PublicFixture::class, InternalModeFixture::class, PairedFixture::class]),
        );
    }

    /**
     * @param list<string> $types
     *
     * @return list<string>
     */
    private static function exposuresIn(array $types): array
    {
        $internal = self::internalTypes($types);
        $exposures = [];

        foreach ($types as $name) {
            if (isset($internal[$name])) {
                continue;
            }

            $class = new ReflectionClass($name);

            foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() !== $name || self::marksInternal($method->getDocComment())) {
                    continue;
                }

                foreach (self::referencedTypes($method) as $referenced) {
                    if (isset($internal[$referenced])) {
                        $exposures[] = sprintf('%s::%s() exposes %s', self::shortName($name), $method->getName(), self::shortName($referenced));
                    }
                }
            }

            foreach ($class->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
                if ($property->getDeclaringClass()->getName() !== $name || self::marksInternal($property->getDocComment())) {
                    continue;
                }

                foreach (self::namesOf($property->getType()) as $referenced) {
                    if (isset($internal[$referenced])) {
                        $exposures[] = sprintf('%s::$%s exposes %s', self::shortName($name), $property->getName(), self::shortName($referenced));
                    }
                }
            }

            foreach ($class->getReflectionConstants(ReflectionClassConstant::IS_PUBLIC) as $constant) {
                if ($constant->getDeclaringClass()->getName() !== $name || self::marksInternal($constant->getDocComment())) {
                    continue;
                }

                $value = $constant->getValue();
                if (is_object($value) && isset($internal[$value::class])) {
                    $exposures[] = sprintf('%s::%s exposes %s', self::shortName($name), $constant->getName(), self::shortName($value::class));
                }
            }
        }

        sort($exposures);

        return $exposures;
    }

    /**
     * @return list<string>
     */
    private static function referencedTypes(ReflectionMethod $method): array
    {
        $types = self::namesOf($method->getReturnType());

        foreach ($method->getParameters() as $parameter) {
            foreach (self::namesOf($parameter->getType()) as $name) {
                $types[] = $name;
            }
        }

        return $types;
    }

    /**
     * @return list<string>
     */
    private static function namesOf(?ReflectionType $type): array
    {
        if ($type instanceof ReflectionNamedType) {
            return $type->isBuiltin() ? [] : [$type->getName()];
        }

        if ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType) {
            $names = [];
            foreach ($type->getTypes() as $member) {
                foreach (self::namesOf($member) as $name) {
                    $names[] = $name;
                }
            }

            return $names;
        }

        return [];
    }

    /**
     * @param list<string> $types
     *
     * @return array<string, true>
     */
    private static function internalTypes(array $types): array
    {
        $internal = [];

        foreach ($types as $name) {
            if (self::marksInternal((new ReflectionClass($name))->getDocComment())) {
                $internal[$name] = true;
            }
        }

        return $internal;
    }

    private static function marksInternal(string|false $docComment): bool
    {
        return is_string($docComment) && str_contains($docComment, '@internal');
    }

    /**
     * @return list<string>
     */
    private static function declaredTypes(): array
    {
        $root = dirname(__DIR__, 2) . '/src';
        $names = [];

        /** @var iterable<SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($root) + 1, -4);
            $name = 'Elmphant\\Tui\\' . str_replace('/', '\\', $relative);

            if (class_exists($name) || interface_exists($name) || trait_exists($name)) {
                $names[] = $name;
            }
        }

        sort($names);

        return $names;
    }

    private static function shortName(string $type): string
    {
        $position = strrpos($type, '\\');

        return $position === false ? $type : substr($type, $position + 1);
    }
}

/**
 * @internal
 */
enum InternalModeFixture
{
    case On;
}

interface PairedFixture
{
}

final class PublicFixture
{
    public const MODE = InternalModeFixture::On;

    public function paired(): PairedFixture&InternalModeFixture
    {
        throw new LogicException('never called');
    }
}
