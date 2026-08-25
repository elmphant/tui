<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Message;

use Elmphant\Tui\Message\FocusMsg;
use Elmphant\Tui\Message\KeyEventType;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\PasteMsg;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NewMessageTypesTest extends TestCase
{
    #[Test]
    public function keyMsgDefaultsToPress(): void
    {
        $msg = new KeyMsg(key: 'a');
        $this->assertSame(KeyEventType::Press, $msg->eventType);
    }

    #[Test]
    public function pasteMsgHoldsContent(): void
    {
        $msg = new PasteMsg('hello world');
        $this->assertSame('hello world', $msg->content);
    }

    /**
     * A paste is bytes off the wire in whatever encoding the source had. One that
     * cannot be a character makes every UTF-8-aware call on the whole paste answer
     * nothing — a field reading it would drop the paste entire, not the byte.
     */
    #[Test]
    public function aPasteKeepsOnlyTheBytesThatFormCharacters(): void
    {
        self::assertSame('abcd', (new PasteMsg("ab\xffcd"))->content);
        self::assertSame('naïve 😀', (new PasteMsg('naïve 😀'))->content, 'what is already text is untouched');
    }

    #[Test]
    public function focusMsgHoldsFocusState(): void
    {
        $this->assertTrue((new FocusMsg(true))->focused);
        $this->assertFalse((new FocusMsg(false))->focused);
    }
}
