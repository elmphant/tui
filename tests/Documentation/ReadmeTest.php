<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Documentation;

use Elmphant\Tui\Core\App;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * The README is the first code a reader runs, and PHP rejects a hook declared
 * with fewer parameters than the base class. Nothing else executes it.
 */
final class ReadmeTest extends TestCase
{
    /**
     * The widgets a layout example arranges are named but never built, so the
     * example runs against real ones standing in for them.
     */
    private const PLACEHOLDER_WIDGETS = <<<'PHP'
        $sidebar = \Elmphant\Tui\Widget\Text::new('sidebar');
        $main = \Elmphant\Tui\Widget\Text::new('main');
        $details = \Elmphant\Tui\Widget\Text::new('details');
        PHP;

    #[Test]
    public function everyDocumentedHookMatchesTheOneItOverrides(): void
    {
        $mismatched = [];

        foreach (self::documentedHooks() as $name => $documented) {
            $declared = self::declaredSignature($name);

            if ($declared !== $documented) {
                $mismatched[] = sprintf('%s(%s) — App declares (%s)', $name, $documented, $declared);
            }
        }

        self::assertSame([], $mismatched, "The README documents hooks App does not accept:\n" . implode("\n", $mismatched));
    }

    #[Test]
    public function theDocumentedResponsiveLayoutCanBeBuilt(): void
    {
        [$output, $status] = self::runExample(self::blockContaining('Flex::row()'));

        self::assertSame(0, $status, "The README's responsive layout example does not run:\n" . $output);
    }

    #[Test]
    public function theDocumentedProcessConfigurationCanBeBuilt(): void
    {
        [$output, $status] = self::runExample(self::blockContaining('->withOutputChunkBytes('));

        self::assertSame(0, $status, "The README's process configuration example does not run:\n" . $output);
    }

    #[Test]
    public function theDocumentedHintLabelsAreTheOnesTheBindingsProduce(): void
    {
        [$output, $status] = self::runExample(
            self::blockContaining('Keybindings::rowNavigation()') . "\necho json_encode(\$hints);",
        );

        self::assertSame(0, $status, "The README's hint example does not run:\n" . $output);
        self::assertSame(
            ['(Shift+)↑/↓' => 'select', 'Enter' => 'choose'],
            json_decode($output, true),
            'the labels the README shows are the ones the bindings answer with',
        );
    }

    private static function blockContaining(string $needle): string
    {
        $readme = file_get_contents(dirname(__DIR__, 2) . '/README.md');
        self::assertIsString($readme, 'README.md must be readable');

        preg_match_all('/```php\n(.*?)```/s', $readme, $matches);

        foreach ($matches[1] as $block) {
            if (str_contains($block, $needle)) {
                return $block;
            }
        }

        self::fail("No README example contains {$needle}");
    }

    /**
     * @return array{string, int}
     */
    private static function runExample(string $block): array
    {
        $root = dirname(__DIR__, 2);
        $file = tempnam(sys_get_temp_dir(), 'readme') . '.php';

        file_put_contents($file, sprintf(
            "<?php\n\ndeclare(strict_types=1);\n\nrequire %s;\n\n%s\n%s",
            var_export($root . '/vendor/autoload.php', true),
            self::PLACEHOLDER_WIDGETS,
            $block,
        ));

        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' 2>&1', $lines, $status);
        unlink($file);

        return [implode("\n", $lines), $status];
    }

    /**
     * @return array<string, string>
     */
    private static function documentedHooks(): array
    {
        $readme = file_get_contents(dirname(__DIR__, 2) . '/README.md');
        self::assertIsString($readme, 'README.md must be readable');

        preg_match_all('/protected function (on[A-Za-z]+)\(([^)]*)\)/', $readme, $matches, PREG_SET_ORDER);

        $hooks = [];

        foreach ($matches as [, $name, $parameters]) {
            $hooks[$name] = self::normalised($parameters);
        }

        self::assertNotSame([], $hooks, 'The README must document the hooks an App overrides');

        return $hooks;
    }

    private static function declaredSignature(string $name): string
    {
        if (!method_exists(App::class, $name)) {
            return '<no such hook>';
        }

        $parameters = [];

        foreach ((new ReflectionMethod(App::class, $name))->getParameters() as $parameter) {
            $type = $parameter->getType();
            $shortName = $type instanceof ReflectionNamedType ? self::shortName($type->getName()) : '';

            $parameters[] = trim($shortName . ' $' . $parameter->getName());
        }

        return implode(', ', $parameters);
    }

    private static function shortName(string $type): string
    {
        $position = strrpos($type, '\\');

        return $position === false ? $type : substr($type, $position + 1);
    }

    private static function normalised(string $parameters): string
    {
        return implode(', ', array_map(
            static fn (string $parameter): string => (string)preg_replace('/\s+/', ' ', trim($parameter)),
            array_filter(explode(',', $parameters), static fn (string $parameter): bool => trim($parameter) !== ''),
        ));
    }
}
