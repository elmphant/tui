<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Documentation;

use Elmphant\Tui\Application\App;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Run complete PHP examples from README and guides; skip fences explicitly marked `php fragment`.
 */
final class ReadmeTest extends TestCase
{
    private const FRAGMENT_BLOCKS = 11;

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
     * Track fragment count so marking a broken example as a fragment cannot silently bypass execution.
     */
    #[Test]
    public function onlyTheExamplesThatCannotBePastedAreFragments(): void
    {
        $fragments = array_filter(self::blocks(), static fn (array $block): bool => $block[0] === 'fragment');

        self::assertCount(self::FRAGMENT_BLOCKS, $fragments);
    }

    #[Test]
    public function everyLinkBetweenThePagesLeadsToAPage(): void
    {
        $broken = [];

        foreach (self::pages() as $page) {
            preg_match_all('/(?:\]\(|src="|srcset=")(?!https?:)([^)#"]+)/', (string)file_get_contents($page), $links, PREG_SET_ORDER);

            foreach ($links as [$link, $target]) {
                if (!is_file(dirname($page) . '/' . $target)) {
                    $broken[] = basename($page) . ': ' . $link;
                }
            }
        }

        self::assertSame([], $broken, "Links that lead nowhere:\n" . implode("\n", $broken));
    }

    #[Test]
    public function everyLinkToASectionNamesAHeadingOfThatPage(): void
    {
        $broken = [];

        foreach (self::pages() as $page) {
            preg_match_all('/\]\((?!https?:)([^)#]*)#([^)]+)\)/', (string)file_get_contents($page), $links, PREG_SET_ORDER);

            foreach ($links as [$link, $target, $anchor]) {
                preg_match_all('/^#+ (.+)$/m', (string)file_get_contents($target === '' ? $page : dirname($page) . '/' . $target), $headings);
                $slugs = array_map(
                    static fn (string $heading): string => str_replace(' ', '-', (string)preg_replace('/[^a-z0-9 _-]/', '', strtolower($heading))),
                    $headings[1],
                );

                if (!in_array($anchor, $slugs, true)) {
                    $broken[] = basename($page) . ': ' . $link;
                }
            }
        }

        self::assertSame([], $broken, "Links to a section no page has:\n" . implode("\n", $broken));
    }

    /**
     * @return list<array{string, string}> [what the fence says after `php`, the code]
     */
    private static function blocks(): array
    {
        $pages = implode("\n", array_map(static fn (string $page): string => (string)file_get_contents($page), self::pages()));

        preg_match_all('/```php([^\n]*)\n(.*?)```/s', $pages, $matches, PREG_SET_ORDER);
        self::assertNotSame([], $matches, 'the README must carry examples');

        return array_map(
            static fn (array $match): array => [trim($match[1]), $match[2]],
            $matches,
        );
    }

    /**
     * @return list<string>
     */
    private static function pages(): array
    {
        $root = dirname(__DIR__, 2);

        return [$root . '/README.md', ...(glob($root . '/guide/*.md') ?: [])];
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
     * Without a TTY, reaching terminal startup counts as running the example; earlier failures still fail the test.
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
        $pages = implode("\n", array_map(static fn (string $page): string => (string)file_get_contents($page), self::pages()));

        preg_match_all('/protected function (on[A-Za-z]+)\(([^)]*)\)/', $pages, $matches, PREG_SET_ORDER);

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
