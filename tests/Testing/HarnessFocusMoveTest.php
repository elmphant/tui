<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Testing;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Layout;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HarnessFocusMoveTest extends TestCase
{
    /**
     * Focus a command moved has to still be there for the next key, or a test can
     * never press one key to move it and a second to use it.
     */
    #[Test]
    public function aKeyAfterFocusNextReachesTheFollowingComponent(): void
    {
        $harness = ModelHarness::for(new MovingFieldsApp(TextInput::new(), TextInput::new()), 40, 2);

        $harness->dispatch(new KeyMsg('n'))->dispatch(new KeyMsg('x'));

        $model = $harness->model();
        self::assertInstanceOf(MovingFieldsApp::class, $model);
        self::assertSame('', $model->first->value(), 'the keyboard left the first field');
        self::assertSame('x', $model->second->value());
    }
}

final readonly class MovingFieldsApp extends App
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
        return $message->key === 'n'
            ? $this->withCmd(Cmd::focusNext())
            : UpdateResult::keep($this);
    }
}
