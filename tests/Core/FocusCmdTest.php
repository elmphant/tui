<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Core;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Cmd\FocusCmd;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\FocusState;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FocusCmdTest extends TestCase
{
    /**
     * Focus is the tree's to change, not the app's to hold, so an app asks for
     * it the same way it asks for anything else with a side effect.
     */
    #[Test]
    public function askingForAComponentMovesTheKeyboardToIt(): void
    {
        $command = Cmd::focus(ComponentId::named('search'));

        self::assertInstanceOf(FocusCmd::class, $command);
        self::assertEquals(
            new FocusState(ComponentId::named('search')),
            $command->applyTo(new FocusState(), [ComponentId::named('search')], [ComponentId::named('search')]),
        );
    }

    #[Test]
    public function aComponentNotOnScreenCannotTakeTheKeyboard(): void
    {
        $command = Cmd::focus(ComponentId::named('hidden/search'));

        self::assertInstanceOf(FocusCmd::class, $command);
        self::assertNull($command->applyTo(new FocusState(ComponentId::named('list')), [ComponentId::named('list')], [ComponentId::named('list')]));
    }

    #[Test]
    public function aContainerTakesTheKeyboardForTheStopsUnderIt(): void
    {
        $command = Cmd::focus(ComponentId::named('form'));

        self::assertInstanceOf(FocusCmd::class, $command);
        self::assertEquals(
            new FocusState(ComponentId::named('form')),
            $command->applyTo(new FocusState(), [ComponentId::named('form/0')], []),
        );
    }
}
