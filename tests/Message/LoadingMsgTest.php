<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Message;

use Elmphant\Tui\Message\LoadingMsg;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LoadingMsgTest extends TestCase
{
    #[Test]
    public function startCreatesLoadingTrue(): void
    {
        $message = LoadingMsg::start();

        self::assertTrue($message->loading);
        self::assertSame('Loading…', $message->label);
    }

    #[Test]
    public function startWithCustomLabel(): void
    {
        $message = LoadingMsg::start('Fetching packages…');

        self::assertTrue($message->loading);
        self::assertSame('Fetching packages…', $message->label);
    }

    #[Test]
    public function stopCreatesLoadingFalse(): void
    {
        $message = LoadingMsg::stop();

        self::assertFalse($message->loading);
        self::assertSame('', $message->label);
    }
}
