<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Core;

use ArrayObject;
use Elmphant\Tui\Core\App;
use Elmphant\Tui\Core\Component;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\FocusState;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NamedPlainPaneTakesKeysTest extends TestCase
{
    /**
     * A pane its host draws has no focus flag to be asked for, and no update() to
     * be handed a key. Everything that walks the focusables now meets one.
     */
    #[Test]
    public function theHostAnswersTheKeysWhileAPlainPaneHoldsTheKeyboard(): void
    {
        $seen = new ArrayObject();

        (new NamedPlainPaneKeyApp($seen))->update(
            new KeyMsg('x'),
            new Rect(0, 0, 20, 2),
            new FocusState(ComponentId::named('pane')),
        );

        self::assertSame(['x'], $seen->getArrayCopy());
    }
}

final readonly class NamedPlainPaneKeyApp extends App
{
    public function __construct(private ArrayObject $seen)
    {
    }

    public function view(): Widget
    {
        return Component::named('pane', new PlainPane());
    }

    protected function onKey(KeyMsg $message, Rect $screen): UpdateResult
    {
        $this->seen[] = $message->key instanceof Key ? $message->key->value : $message->key;

        return UpdateResult::keep($this);
    }
}
