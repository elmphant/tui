<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Core;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\FocusCmd;
use Elmphant\Tui\Core\FocusState;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FocusNothingCmdTest extends TestCase
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
}
