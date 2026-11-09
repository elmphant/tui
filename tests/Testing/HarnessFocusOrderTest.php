<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Testing;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
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

        self::assertSame(0, $frame->cursorPosition()?->y, 'the harness reads the component order from the model, so any pair of names works');
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
