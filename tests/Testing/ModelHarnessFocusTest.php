<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Testing;

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

final class ModelHarnessFocusTest extends TestCase
{
    #[Test]
    public function theKeyboardStartsOnTheFirstComponent(): void
    {
        $driven = ModelHarness::for(new TwoNamedFieldApp(TextInput::new(), TextInput::new()), 40, 2)->init();

        self::assertTrue(
            $driven->focused()?->equals(ComponentId::named('first')),
            'nothing asked for the keyboard, so it lands where the tree begins',
        );
    }

    #[Test]
    public function nobodyHoldsTheKeyboardOnceTheAppSendsItNowhere(): void
    {
        $driven = ModelHarness::for(new TwoNamedFieldApp(TextInput::new(), TextInput::new()), 40, 2)->init();

        $driven->dispatch(new KeyMsg('x', ctrl: true));

        self::assertNull($driven->focused(), 'nowhere is an answer, not a gap left for the tree to fill');
    }
}

final readonly class TwoNamedFieldApp extends App
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

    protected function onKey(KeyMsg $message, Rect $screen): UpdateResult
    {
        return match ($message->ctrl ? $message->key : null) {
            'n' => $this->then(Cmd::focusNext()),
            'x' => $this->then(Cmd::focusNowhere()),
            default => UpdateResult::ignored($this),
        };
    }
}
