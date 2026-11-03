<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Core;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Cmd\FocusCmd;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\FocusState;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FocusCycleCmdTest extends TestCase
{
    #[Test]
    public function cyclingMovesTheKeyboardToTheNextComponentInTreeOrder(): void
    {
        $command = Cmd::focusNext();
        self::assertInstanceOf(FocusCmd::class, $command);

        $moved = $command->applyTo(
            FocusState::on(ComponentId::named('search')),
            [ComponentId::named('search'), ComponentId::named('results')],
            [],
        );

        self::assertEquals(ComponentId::named('results'), $moved?->focused());
    }
}
