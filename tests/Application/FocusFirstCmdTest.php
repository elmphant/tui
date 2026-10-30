<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FocusFirstCmdTest extends TestCase
{
    #[Test]
    public function theKeyboardGoesBackToWhereTheTreeBegins(): void
    {
        $driven = ModelHarness::for(new WalkedAwayApp(TextInput::new(), TextInput::new()), 40, 2)->initialize();
        $driven->dispatch(new KeyMsg('n'));

        $driven->dispatch(new KeyMsg('b'));

        self::assertTrue(
            $driven->focused()?->equals(ComponentId::named('first')),
            'the keyboard had walked on, and asking for the start has to bring it back',
        );
    }
}

final readonly class WalkedAwayApp extends App
{
    public function __construct(
        public TextInput $first,
        public TextInput $second,
    ) {
    }

    public function view(): Widget
    {
        return Layout::vertical(
            Component::bind('first', $this->first, static fn (self $app, TextInput $field): self => $app->cloneWith(first: $field)),
            Component::bind('second', $this->second, static fn (self $app, TextInput $field): self => $app->cloneWith(second: $field)),
        );
    }

    protected function onKey(KeyMsg $message, Rect $screen): UpdateResult
    {
        return match ($message->key) {
            'n' => $this->then(Cmd::focusNext()),
            'b' => $this->then(Cmd::focusFirst()),
            default => UpdateResult::ignored($this),
        };
    }
}
