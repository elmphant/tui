<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Api;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class DeclaredExtensionsTest extends TestCase
{
    private const ALWAYS_PRESENT = ['core', 'date', 'hash', 'json', 'pcre', 'random', 'reflection', 'spl', 'standard'];

    #[Test]
    public function composerDeclaresEveryExtensionTheSourceCalls(): void
    {
        $composer = json_decode((string)file_get_contents(__DIR__ . '/../../composer.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($composer);
        $declared = array_map(
            static fn (string $package): string => strtolower(substr($package, 4)),
            array_filter(
                [...array_keys((array)$composer['require']), ...array_keys((array)$composer['suggest'])],
                static fn (string $package): bool => str_starts_with($package, 'ext-'),
            ),
        );

        $undeclared = array_values(array_diff(self::extensionsCalledIn(__DIR__ . '/../../src'), self::ALWAYS_PRESENT, $declared));

        self::assertSame([], $undeclared, 'composer.json must require (or suggest) ext-' . implode(', ext-', $undeclared));
    }

    /**
     * @return list<string>
     */
    private static function extensionsCalledIn(string $directory): array
    {
        $extensionOf = [];
        foreach (get_loaded_extensions() as $extension) {
            foreach (get_extension_funcs($extension) ?: [] as $function) {
                $extensionOf[strtolower($function)] = strtolower($extension);
            }
        }

        $called = [];
        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            foreach (self::functionsCalledIn((string)file_get_contents($file->getPathname())) as $function) {
                if (isset($extensionOf[$function])) {
                    $called[$extensionOf[$function]] = true;
                }
            }
        }

        $extensions = array_keys($called);
        sort($extensions);

        return $extensions;
    }

    /**
     * @return list<string>
     */
    private static function functionsCalledIn(string $source): array
    {
        $tokens = array_values(array_filter(
            token_get_all($source),
            static fn (array|string $token): bool => !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));

        $functions = [];
        foreach ($tokens as $index => $token) {
            if (!is_array($token) || !in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true) || ($tokens[$index + 1] ?? null) !== '(') {
                continue;
            }
            $previous = $tokens[$index - 1] ?? null;
            if (is_array($previous) && in_array($previous[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true)) {
                continue;
            }
            $functions[] = strtolower(ltrim($token[1], '\\'));
        }

        return $functions;
    }
}
