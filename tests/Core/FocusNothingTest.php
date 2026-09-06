<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Core;

use ArrayObject;
use Elmphant\Tui\Core\App;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Cmd\FocusCmd;
use Elmphant\Tui\Core\Component;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\FocusState;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Putting the keyboard down is an answer, so nothing may pick it back up: not the
 * runtime resolving a ring, and not a widget still carrying a focus flag from before.
 */
final class FocusNothingTest extends TestCase
{
    #[Test]
    public function askingForNobodyPutsTheKeyboardDown(): void
    {
        $command = Cmd::focusNothing();

        self::assertInstanceOf(FocusCmd::class, $command);
        self::assertEquals(
            FocusState::nowhere(),
            $command->applyTo(
                new FocusState(ComponentId::named('search')),
                [ComponentId::named('search')],
            ),
        );
    }

    #[Test]
    public function aKeyboardPutDownStaysDownWhileThereAreStillFocusables(): void
    {
        $resolved = FocusState::nowhere()
            ->resolvedAgainst([ComponentId::named('first'), ComponentId::named('second')]);

        self::assertNull($resolved->focused());
    }

    #[Test]
    public function aFlaggedWidgetGetsNoKeysWhileTheKeyboardIsDown(): void
    {
        $app = new StaleFlagApp(TextInput::new());

        $result = $app->update(new KeyMsg('x'), Rect::fromSize(40, 4), FocusState::nowhere());

        self::assertSame('', $result->model->field->value());
    }

    #[Test]
    public function theAppAnswersTheKeysWhileTheKeyboardIsDown(): void
    {
        $seen = new ArrayObject();

        $result = (new SelfAnsweringApp($seen))
            ->update(new KeyMsg('x'), Rect::fromSize(40, 4), FocusState::nowhere());

        self::assertSame(['x'], $seen->getArrayCopy());
        self::assertSame('', $result->model->field->value());
    }
}

final readonly class StaleFlagApp extends App
{
    public function __construct(
        public TextInput $field = new TextInput(),
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
}

final readonly class SelfAnsweringApp extends App
{
    public function __construct(
        private ArrayObject $seen,
        public TextInput $field = new TextInput(),
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

    protected function onKey(KeyMsg $message, Rect $screen): UpdateResult
    {
        $this->seen[] = $message->key;

        return UpdateResult::keep($this);
    }
}
