<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Message;

use Elmphant\Tui\Message\ProcessFinishedMsg;
use Elmphant\Tui\Message\ProcessResultMsg;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProcessEndTest extends TestCase
{
    #[Test]
    public function onlyExitCodeZeroIsASuccess(): void
    {
        self::assertTrue((new ProcessResultMsg('', '', 0))->isSuccessful());
        self::assertFalse((new ProcessResultMsg('', '', 1))->isSuccessful());
        self::assertTrue((new ProcessFinishedMsg(0))->isSuccessful());
        self::assertFalse((new ProcessFinishedMsg(2))->isSuccessful());
    }

    #[Test]
    public function aProcessWithNoExitCodeDidNotSucceed(): void
    {
        self::assertFalse((new ProcessResultMsg('', '', null))->isSuccessful());
        self::assertFalse((new ProcessFinishedMsg(null))->isSuccessful());
    }
}
