<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Documentation;

use Elmphant\Tui\Application\App;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * The README is the first code a reader runs, so every example in it is run
 * here. A block a reader cannot paste as it stands — a method body, a menu of
 * calls, loose match arms — says so in its fence (```php fragment) and is
 * skipped; everything else must reach the terminal or finish.
 */
final class ReadmeTest extends TestCase
{
    private const FRAGMENT_BLOCKS = 11;

    /**
     * What an example names but never builds, standing in as the real thing.
     */
    private const PLACEHOLDERS = <<<'PHP'
        $sidebar = \Elmphant\Tui\Widget\Prose\Text::new('sidebar');
        $main = \Elmphant\Tui\Widget\Prose\Text::new('main');
        $details = \Elmphant\Tui\Widget\Prose\Text::new('details');
        $content = \Elmphant\Tui\Widget\Prose\Text::new('content');
        $ratio = 0.42;
        $frame = new \Elmphant\Tui\Rendering\Frame(80, 24);
        $area = \Elmphant\Tui\Layout\Rect::fromSize(80, 24);
        final readonly class ReadmeApp extends \Elmphant\Tui\Application\App {
            public function view(): \Elmphant\Tui\Widget\Widget
            {
                return \Elmphant\Tui\Widget\Prose\Text::new('placeholder');
            }
        }
        $app = $model = new ReadmeApp();
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
    public function everyDocumentedExampleRuns(): void
    {
        $broken = [];

        foreach (self::blocks() as $index => [$info, $code]) {
            if ($info === 'fragment') {
                continue;
            }

            [$output, $ranOrReachedTheTerminal] = self::runExample($code);

            if (!$ranOrReachedTheTerminal) {
                $broken[] = sprintf("block %d (%s):\n%s", $index, strtok(trim($code), "\n"), $output);
            }
        }

        self::assertSame([], $broken, "README examples that no longer run:\n\n" . implode("\n\n", $broken));
    }

    #[Test]
    public function theDocumentedHintLabelsAreTheOnesTheBindingsProduce(): void
    {
        [$output, $ran] = self::runExample(
            self::blockContaining('Keybindings::rowNavigation()') . "\necho json_encode(\$hints);",
        );

        self::assertTrue($ran, "The README's hint example does not run:\n" . $output);
        self::assertSame(
            ['(Shift+)↑/↓' => 'select', 'Enter' => 'choose'],
            json_decode($output, true),
            'the labels the README shows are the ones the bindings answer with',
        );
    }

    /**
     * A fragment is an example nothing runs, so the count is worth noticing: it
     * should go down as examples are made paste-able, and never quietly up
     * because a fence was the easy way past a failing example.
     */
    #[Test]
    public function onlyTheExamplesThatCannotBePastedAreFragments(): void
    {
        $fragments = array_filter(self::blocks(), static fn (array $block): bool => $block[0] === 'fragment');

        self::assertCount(self::FRAGMENT_BLOCKS, $fragments);
    }

    /**
     * @return list<array{string, string}> [what the fence says after `php`, the code]
     */
    private static function blocks(): array
    {
        $readme = file_get_contents(dirname(__DIR__, 2) . '/README.md');
        self::assertIsString($readme, 'README.md must be readable');

        preg_match_all('/```php([^\n]*)\n(.*?)```/s', $readme, $matches, PREG_SET_ORDER);
        self::assertNotSame([], $matches, 'the README must carry examples');

        return array_map(
            static fn (array $match): array => [trim($match[1]), $match[2]],
            $matches,
        );
    }

    private static function blockContaining(string $needle): string
    {
        foreach (self::blocks() as [, $code]) {
            if (str_contains($code, $needle)) {
                return $code;
            }
        }

        self::fail("No README example contains {$needle}");
    }

    /**
     * A block that starts a program gets as far as the terminal and no further —
     * this suite has no TTY — so reaching that boundary counts as having run:
     * everything the example spells out was accepted on the way.
     *
     * @return array{string, bool}
     */
    private static function runExample(string $block): array
    {
        $root = dirname(__DIR__, 2);
        $file = tempnam($root, 'readme-') . '.php';

        file_put_contents($file, str_starts_with(ltrim($block), '<?php')
            ? $block
            : sprintf(
                "<?php\n\ndeclare(strict_types=1);\n\nrequire %s;\n\n%s\n%s",
                var_export($root . '/vendor/autoload.php', true),
                self::PLACEHOLDERS,
                $block,
            ));

        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' 2>&1', $lines, $status);
        unlink(substr($file, 0, -4));
        unlink($file);

        $output = implode("\n", $lines);

        return [$output, $status === 0 || str_contains($output, 'requires stdin to be a TTY')];
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
