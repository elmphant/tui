<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Testing;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Application\InputContext;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HarnessFocusTest extends TestCase
{
    #[Test]
    public function theFirstComponentDrawsFocusedWithoutBeingAsked(): void
    {
        $frame = ModelHarness::for(new TwoFieldApp(TextInput::new(), TextInput::new()), 40, 2)->renderFrame();

        self::assertSame(0, $frame->cursorPosition()?->y, 'the keyboard lands on the first component, so its caret is on the first row');
    }
}

final readonly class TwoFieldApp extends App
{
    public function __construct(
        public TextInput $first,
        public TextInput $second,
    ) {
    }

    public function view(): Widget
    {
        return Layout::column(
            Component::bind('first', $this->first, static fn (self $app, TextInput $field): self => $app->cloneWith(first: $field)),
            Component::bind('second', $this->second, static fn (self $app, TextInput $field): self => $app->cloneWith(second: $field)),
        );
    }

    protected function onKey(KeyMsg $message, InputContext $input): UpdateResult
    {
        return match ($message->key) {
            'n' => new UpdateResult($this, Cmd::focusNext()),
            default => new UpdateResult($this),
        };
    }
}
