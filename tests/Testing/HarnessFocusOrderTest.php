<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Testing;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HarnessFocusOrderTest extends TestCase
{
    #[Test]
    public function theFirstComponentDrawsFocusedWhateverItIsNamed(): void
    {
        $frame = ModelHarness::for(new RenamedFieldsApp(TextInput::new(), TextInput::new()), 40, 2)->renderFrame();

        self::assertNotSame(
            self::row($frame, 1, 40),
            self::row($frame, 0, 40),
            'the harness reads the component order from the model, so any pair of names works',
        );
    }

    private static function row(Frame $frame, int $y, int $width): string
    {
        $offset = $y * $width;

        return implode('|', array_slice($frame->foregrounds, $offset, $width))
            . '#' . implode('|', array_slice($frame->backgrounds, $offset, $width))
            . '#' . implode('|', array_slice($frame->attributes, $offset, $width));
    }
}

final readonly class RenamedFieldsApp extends App
{
    public function __construct(
        public TextInput $alpha,
        public TextInput $beta,
    ) {
    }

    public function view(): Widget
    {
        return Layout::column(
            Component::bind('alpha', $this->alpha, static fn (self $app, TextInput $field): self => $app->cloneWith(alpha: $field)),
            Component::bind('beta', $this->beta, static fn (self $app, TextInput $field): self => $app->cloneWith(beta: $field)),
        );
    }
}
