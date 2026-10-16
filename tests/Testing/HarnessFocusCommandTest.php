<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Testing;

use ArrayObject;
use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HarnessFocusCommandTest extends TestCase
{
    /**
     * A harness that settles the keyboard on the first component it finds cannot
     * show an app putting it down, which is how a real one starts.
     */
    #[Test]
    public function aKeyboardPutDownAtStartupStaysDown(): void
    {
        $seen = new ArrayObject();
        $harness = ModelHarness::for(new KeyboardDownApp($seen, TextInput::new()), 40, 4);

        $harness->initialize()->dispatch(new KeyMsg('x'));

        self::assertSame(['x'], $seen->getArrayCopy(), 'the app answers the key itself');
        self::assertInstanceOf(KeyboardDownApp::class, $harness->model());
        self::assertSame('', $harness->model()->field->value());
    }
}

final readonly class KeyboardDownApp extends App
{
    public function __construct(
        private ArrayObject $seen,
        public TextInput $field,
    ) {
    }

    public function view(): Widget
    {
        return Component::bind(
            'field',
            $this->field,
            static fn (self $app, TextInput $field): self => $app->cloneWith(field: $field),
        );
    }

    protected function setup(): ?Cmd
    {
        return Cmd::focusNothing();
    }

    protected function onKey(KeyMsg $message, Rect $screen): UpdateResult
    {
        $this->seen[] = $message->key;

        return UpdateResult::keep($this);
    }
}
