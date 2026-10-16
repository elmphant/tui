<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Testing;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The harness stands in for Program, so an app driven by it has to see the same
 * keyboard it would see running for real.
 */
final class HarnessFocusTest extends TestCase
{
    #[Test]
    public function theFirstComponentDrawsFocusedWithoutBeingAsked(): void
    {
        $frame = ModelHarness::for(new TwoFieldApp(TextInput::new(), TextInput::new()), 40, 2)->renderFrame();

        self::assertNotSame(
            self::row($frame, 1, 40),
            self::row($frame, 0, 40),
            'the keyboard lands on the first component, so its row must not look like the second',
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

final readonly class TwoFieldApp extends App
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
            default => UpdateResult::keep($this),
        };
    }
}
