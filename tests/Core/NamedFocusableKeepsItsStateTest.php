<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Core;

use ArrayObject;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\FocusState;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\KeyMsg;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NamedFocusableKeepsItsStateTest extends TestCase
{
    /**
     * Nothing can write a named pane back, so handing it a key would throw the
     * answer away. The host reads the keys for it.
     */
    #[Test]
    public function aNamedFocusableIsNeverHandedTheKey(): void
    {
        $seen = new ArrayObject();

        $result = (new NamedOnlyApp($seen))->update(
            new KeyMsg('x'),
            new Rect(0, 0, 8, 1),
            new FocusState(ComponentId::named('pane')),
        );

        self::assertSame([], $seen->getArrayCopy(), 'the pane was not asked to answer it');
        self::assertInstanceOf(NamedOnlyApp::class, $result->model);
    }
}
