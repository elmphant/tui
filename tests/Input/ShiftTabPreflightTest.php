<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Input;

use Elmphant\Tui\Input\InputParser;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ShiftTabPreflightTest extends TestCase
{
    #[Test]
    public function asciiBackTabCsiZYieldsShiftTab(): void
    {
        $result = InputParser::parse("\x1b[Z", flush: true);

        self::assertCount(1, $result->messages);
        $msg = $result->messages[0];
        self::assertInstanceOf(KeyMsg::class, $msg);
        self::assertSame(Key::Tab, $msg->key);
        self::assertTrue($msg->shift, 'CSI Z (back-tab) must be parsed as Shift+Tab');
    }
}
