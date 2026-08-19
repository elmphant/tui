<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Testing;

use Elmphant\Tui\Core\App;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Component;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Layout;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\TextInput;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Where the keyboard is belongs to the runtime, so a test driving one asks the
 * harness rather than an app keeping a copy of the answer.
 */
final class HarnessNamesWhereTheKeyboardIsTest extends TestCase
{
    #[Test]
    public function theKeyboardStartsOnTheFirstComponent(): void
    {
        $driven = ModelHarness::for(new TwoNamedFieldApp(), 40, 2)->initialize();

        self::assertTrue(
            $driven->focused()?->equals(ComponentId::named('first')),
            'nothing asked for the keyboard, so it lands where the tree begins',
        );
    }

    #[Test]
    public function nobodyHoldsTheKeyboardOnceTheAppSendsItNowhere(): void
    {
        $driven = ModelHarness::for(new TwoNamedFieldApp(), 40, 2)->initialize();

        $driven->dispatch(new KeyMsg('x'));

        self::assertNull($driven->focused(), 'nowhere is an answer, not a gap left for the tree to fill');
    }
}

final readonly class TwoNamedFieldApp extends App
{
    public function __construct(
        public TextInput $first = new TextInput(),
        public TextInput $second = new TextInput(),
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
            'n' => $this->withCmd(Cmd::focusNext()),
            'x' => $this->withCmd(Cmd::focusNothing()),
            default => UpdateResult::keep($this),
        };
    }
}
