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
    #[Test]
    public function askingForAComponentMovesTheKeyboardToIt(): void
    {
        $command = Cmd::focus(ComponentId::named('search'));

        self::assertInstanceOf(FocusCmd::class, $command);
        self::assertEquals(
            FocusState::on(ComponentId::named('search')),
            $command->applyTo(FocusState::undecided(), [ComponentId::named('search')], [ComponentId::named('search')]),
        );
    }

    #[Test]
    public function aComponentCanBeNamedByTheStringItWasBoundWith(): void
    {
        self::assertEquals(Cmd::focus(ComponentId::named('search')), Cmd::focus('search'));
    }

    #[Test]
    public function aComponentNotOnScreenCannotTakeTheKeyboard(): void
    {
        $command = Cmd::focus(ComponentId::named('hidden/search'));

        self::assertInstanceOf(FocusCmd::class, $command);
        self::assertNull($command->applyTo(FocusState::on(ComponentId::named('list')), [ComponentId::named('list')], [ComponentId::named('list')]));
    }

    #[Test]
    public function aContainerTakesTheKeyboardForTheStopsUnderIt(): void
    {
        $command = Cmd::focus(ComponentId::named('form'));

        self::assertInstanceOf(FocusCmd::class, $command);
        self::assertEquals(
            FocusState::on(ComponentId::named('form')),
            $command->applyTo(FocusState::undecided(), [ComponentId::named('form/0')], []),
        );
    }
}
