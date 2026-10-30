<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Message;

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
     * One malformed paste byte must not cause UTF-8 operations to discard the whole paste.
     */
    #[Test]
    public function aPasteKeepsOnlyTheBytesThatFormCharacters(): void
    {
        self::assertSame('abcd', (new PasteMsg("ab\xffcd"))->content);
        self::assertSame('naïve 😀', (new PasteMsg('naïve 😀'))->content, 'what is already text is untouched');
    }

    #[Test]
    public function aCtrlLetterANestedTmuxEncodedInAPasteIsItsControlByte(): void
    {
        self::assertSame("one\ntwo\nthree", (new PasteMsg("one\x1b[106;5utwo\x1b[27;5;106~three"))->content);
    }
}
