<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Message;

use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class KeyMsgTest extends TestCase
{
    #[Test]
    public function characterKey(): void
    {
        $msg = new KeyMsg('q');
        self::assertSame('q', $msg->key);
        self::assertFalse($msg->ctrl);
        self::assertFalse($msg->alt);
        self::assertFalse($msg->shift);
    }

    #[Test]
    public function specialKey(): void
    {
        $msg = new KeyMsg(Key::Up);
        self::assertSame(Key::Up, $msg->key);
    }

    #[Test]
    public function ctrlModifier(): void
    {
        $msg = new KeyMsg('c', ctrl: true);
        self::assertTrue($msg->ctrl);
    }

    #[Test]
    public function matchInSwitch(): void
    {
        $msg = new KeyMsg(Key::Up);
        $result = match ($msg->key) {
            Key::Up, 'k' => 'up',
            Key::Down, 'j' => 'down',
            default => 'other',
        };
        self::assertSame('up', $result);
    }

    #[Test]
    public function matchCharInSwitch(): void
    {
        $msg = new KeyMsg('k');
        $result = match ($msg->key) {
            Key::Up, 'k' => 'up',
            Key::Down, 'j' => 'down',
            default => 'other',
        };
        self::assertSame('up', $result);
    }

    #[Test]
    public function modifierCombinations(): void
    {
        $msg = new KeyMsg('a', ctrl: true, alt: true, shift: true);
        self::assertTrue($msg->ctrl);
        self::assertTrue($msg->alt);
        self::assertTrue($msg->shift);
    }

    #[Test]
    public function defaultModifiersAreFalse(): void
    {
        $msg = new KeyMsg('a');
        self::assertFalse($msg->ctrl);
        self::assertFalse($msg->alt);
        self::assertFalse($msg->shift);
    }
}
