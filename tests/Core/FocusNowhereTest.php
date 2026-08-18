<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Core;

use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\FocusState;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * An app that answers the keys itself needs to say so. Without this the runtime
 * puts the keyboard back on the first component every frame, and a pane that
 * should rest un-entered cannot.
 */
final class FocusNowhereTest extends TestCase
{
    #[Test]
    public function aKeyboardPutDownStaysDownWhileThereAreStillFocusables(): void
    {
        $resolved = FocusState::nowhere()
            ->resolvedAgainst([ComponentId::named('first'), ComponentId::named('second')]);

        self::assertNull($resolved->focused());
    }
}
