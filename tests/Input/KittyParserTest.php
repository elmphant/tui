<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Input;

use Elmphant\Tui\Input\InputParser;
use Elmphant\Tui\Message\FocusMsg;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyEventType;
use Elmphant\Tui\Message\KeyMsg;
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

    #[\PHPUnit\Framework\Attributes\DataProvider('kittyKeypadCharacterProvider')]
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
    public function focusIn(): void
    {
        $result = InputParser::parseSequence("\x1b[I");
        $this->assertInstanceOf(FocusMsg::class, $result);
        $this->assertTrue($result->focused);
    }

    #[Test]
    public function focusOut(): void
    {
        $result = InputParser::parseSequence("\x1b[O");
        $this->assertInstanceOf(FocusMsg::class, $result);
        $this->assertFalse($result->focused);
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
}
