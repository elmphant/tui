<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Input;

use Elmphant\Tui\Input\InputParser;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyEventType;
use Elmphant\Tui\Message\KeyMsg;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class KittyParserTest extends TestCase
{
    #[Test]
    public function kittyLetterA(): void
    {
        $result = InputParser::parseSequence("\x1b[97u");
        $this->assertInstanceOf(KeyMsg::class, $result);
        $this->assertSame('a', $result->key);
        $this->assertSame(KeyEventType::Press, $result->eventType);
    }

    #[Test]
    public function kittyWithCtrlShift(): void
    {
        $result = InputParser::parseSequence("\x1b[97;6u");
        $this->assertInstanceOf(KeyMsg::class, $result);
        $this->assertSame('a', $result->key);
        $this->assertTrue($result->ctrl);
        $this->assertTrue($result->shift);
        $this->assertFalse($result->alt);
    }

    #[Test]
    public function kittyKeypadEnterMapsToEnter(): void
    {
        $result = InputParser::parseSequence("\x1b[57414u");
        $this->assertInstanceOf(KeyMsg::class, $result);
        $this->assertSame(Key::Enter, $result->key);
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function kittyKeypadCharacterProvider(): iterable
    {
        yield 'KP 0' => [57399, '0'];
        yield 'KP 1' => [57400, '1'];
        yield 'KP 2' => [57401, '2'];
        yield 'KP 3' => [57402, '3'];
        yield 'KP 4' => [57403, '4'];
        yield 'KP 5' => [57404, '5'];
        yield 'KP 6' => [57405, '6'];
        yield 'KP 7' => [57406, '7'];
        yield 'KP 8' => [57407, '8'];
        yield 'KP 9' => [57408, '9'];
        yield 'KP decimal' => [57409, '.'];
        yield 'KP divide' => [57410, '/'];
        yield 'KP multiply' => [57411, '*'];
        yield 'KP minus' => [57412, '-'];
        yield 'KP plus' => [57413, '+'];
        yield 'KP equals' => [57415, '='];
    }

    #[DataProvider('kittyKeypadCharacterProvider')]
    #[Test]
    public function kittyKeypadCharacters(int $codepoint, string $expectedCharacter): void
    {
        $result = InputParser::parseSequence("\x1b[{$codepoint}u");
        $this->assertInstanceOf(KeyMsg::class, $result);
        $this->assertSame($expectedCharacter, $result->key);
    }

    #[Test]
    public function kittyRepeatEvent(): void
    {
        $result = InputParser::parseSequence("\x1b[97;1:2u");
        $this->assertInstanceOf(KeyMsg::class, $result);
        $this->assertSame(KeyEventType::Repeat, $result->eventType);
    }

    #[Test]
    public function aWindowFocusReportIsIgnoredRatherThanTyped(): void
    {
        self::assertSame([null, null], [InputParser::parseSequence("\x1b[I"), InputParser::parseSequence("\x1b[O")]);
    }

    #[Test]
    public function legacyArrowUpViaParseSequence(): void
    {
        $result = InputParser::parseSequence("\x1b[A");
        $this->assertInstanceOf(KeyMsg::class, $result);
        $this->assertSame(Key::Up, $result->key);
    }

    #[Test]
    public function legacyPlainCharacterViaParseSequence(): void
    {
        $result = InputParser::parseSequence('x');
        $this->assertInstanceOf(KeyMsg::class, $result);
        $this->assertSame('x', $result->key);
    }

    /**
     * Kitty reports super, hyper and meta, which a KeyMsg cannot carry. Reading only
     * the three bits we have delivered cmd+a as a plain "a".
     */
    #[Test]
    public function superHeldDownIsNotDeliveredAsAPlainLetter(): void
    {
        self::assertNull(InputParser::parseSequence("\x1b[97;9u"), 'cmd+a is not an a');
        self::assertNull(InputParser::parseSequence("\x1b[97;17u"), 'hyper');
        self::assertNull(InputParser::parseSequence("\x1b[97;33u"), 'meta');
    }

    #[Test]
    public function anEmptyModifierFieldIsAnUnmodifiedPress(): void
    {
        self::assertEquals(new KeyMsg('a'), InputParser::parseSequence("\x1b[97;;97u"));
    }

    /**
     * The locks share that field, so dropping an unnameable modifier must not start
     * dropping letters typed with caps lock on.
     */
    #[Test]
    public function capsLockLeavesTheLetterAlone(): void
    {
        $message = InputParser::parseSequence("\x1b[97;65u");

        self::assertInstanceOf(KeyMsg::class, $message);
        self::assertSame('a', $message->key);
    }
}
