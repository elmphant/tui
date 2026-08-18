<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Core;

use ArrayObject;
use Elmphant\Tui\Core\App;
use Elmphant\Tui\Core\Component;
use Elmphant\Tui\Core\FocusState;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Widget\TextInput;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FocusNothingRoutingTest extends TestCase
{
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
