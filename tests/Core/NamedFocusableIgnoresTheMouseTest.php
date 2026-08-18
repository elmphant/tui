<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Core;

use ArrayObject;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseButton;
use Elmphant\Tui\Message\MouseMsg;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NamedFocusableIgnoresTheMouseTest extends TestCase
{
    /**
     * The pointer reaches every bound widget, so a named pane would be handed a
     * click as well — and nothing could store what it answered.
     */
    #[Test]
    public function aClickOnANamedFocusableIsLeftToItsHost(): void
    {
        $seen = new ArrayObject();

        $result = (new NamedOnlyApp($seen))->update(
            new MouseMsg(0, 0, MouseAction::Press, MouseButton::Left),
            new Rect(0, 0, 8, 1),
        );

        self::assertSame([], $seen->getArrayCopy());
        self::assertInstanceOf(NamedOnlyApp::class, $result->model);
    }
}
