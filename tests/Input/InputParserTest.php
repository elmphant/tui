<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Input;

use Elmphant\Tui\Input\InputParser;
use Elmphant\Tui\Input\InputParseResult;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyEventType;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseButton;
use Elmphant\Tui\Message\MouseMsg;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class InputParserTest extends TestCase
{
    #[Test]
    public function parseCharacterKey(): void
    {
        $messages = InputParser::parse('q')->messages;
        self::assertCount(1, $messages);
        self::assertInstanceOf(KeyMsg::class, $messages[0]);
        self::assertSame('q', $messages[0]->key);
    }

    /**
     * @return array<string, array{string, Key}>
     */
    public static function simpleKeyProvider(): array
    {
        return [
            'Up Arrow' => ["\x1b[A", Key::Up],
            'Down Arrow' => ["\x1b[B", Key::Down],
            'Enter' => ["\r", Key::Enter],
            'Tab' => ["\t", Key::Tab],
        ];
    }

    #[DataProvider('simpleKeyProvider')]
    #[Test]
    public function parseSimpleKeys(string $input, Key $expectedKey): void
    {
        $messages = InputParser::parse($input)->messages;
        self::assertCount(1, $messages);
        self::assertInstanceOf(KeyMsg::class, $messages[0]);
        self::assertSame($expectedKey, $messages[0]->key);
    }

    /**
     * @return array<string, array{string, Key}>
     */
    public static function ss3ApplicationModeProvider(): array
    {
        return [
            'Up (DECCKM)' => ["\x1bOA", Key::Up],
            'Down (DECCKM)' => ["\x1bOB", Key::Down],
            'Right (DECCKM)' => ["\x1bOC", Key::Right],
            'Left (DECCKM)' => ["\x1bOD", Key::Left],
            'keypad Enter' => ["\x1bOM", Key::Enter],
        ];
    }

    #[DataProvider('ss3ApplicationModeProvider')]
    #[Test]
    public function parseSs3ApplicationModeKeys(string $input, Key $expectedKey): void
    {
        $messages = InputParser::parse($input)->messages;
        self::assertCount(1, $messages);
        self::assertInstanceOf(KeyMsg::class, $messages[0]);
        self::assertSame($expectedKey, $messages[0]->key);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function ss3KeypadCharacterProvider(): array
    {
        return [
            'KP multiply' => ["\x1bOj", '*'],
            'KP plus' => ["\x1bOk", '+'],
            'KP minus' => ["\x1bOm", '-'],
            'KP decimal' => ["\x1bOn", '.'],
            'KP divide' => ["\x1bOo", '/'],
            'KP separator' => ["\x1bOl", ','],
            'KP 0' => ["\x1bOp", '0'],
            'KP 1' => ["\x1bOq", '1'],
            'KP 2' => ["\x1bOr", '2'],
            'KP 3' => ["\x1bOs", '3'],
            'KP 4' => ["\x1bOt", '4'],
            'KP 5' => ["\x1bOu", '5'],
            'KP 6' => ["\x1bOv", '6'],
            'KP 7' => ["\x1bOw", '7'],
            'KP 8' => ["\x1bOx", '8'],
            'KP 9' => ["\x1bOy", '9'],
            'KP equals' => ["\x1bOX", '='],
        ];
    }

    #[DataProvider('ss3KeypadCharacterProvider')]
    #[Test]
    public function parseSs3KeypadCharacters(string $input, string $expectedCharacter): void
    {
        $messages = InputParser::parse($input)->messages;
        self::assertCount(1, $messages);
        self::assertInstanceOf(KeyMsg::class, $messages[0]);
        self::assertSame($expectedCharacter, $messages[0]->key);
    }

    /**
     * @return array<string, array{string, Key}>
     */
    public static function legacyTildeKeyProvider(): array
    {
        return [
            'Home (vt220 1~)' => ["\x1b[1~", Key::Home],
            'End (vt220 4~)' => ["\x1b[4~", Key::End],
            'Home (rxvt 7~)' => ["\x1b[7~", Key::Home],
            'End (rxvt 8~)' => ["\x1b[8~", Key::End],
            'F1 (legacy 11~)' => ["\x1b[11~", Key::F1],
            'F2 (legacy 12~)' => ["\x1b[12~", Key::F2],
            'F3 (legacy 13~)' => ["\x1b[13~", Key::F3],
            'F4 (legacy 14~)' => ["\x1b[14~", Key::F4],
        ];
    }

    #[DataProvider('legacyTildeKeyProvider')]
    #[Test]
    public function parseLegacyTildeKeys(string $input, Key $expectedKey): void
    {
        $messages = InputParser::parse($input)->messages;
        self::assertCount(1, $messages);
        self::assertInstanceOf(KeyMsg::class, $messages[0]);
        self::assertSame($expectedKey, $messages[0]->key);
    }

    /**
     * @return array<string, array{string, Key}>
     */
    public static function rxvtShiftedArrowProvider(): array
    {
        return [
            'shift Up' => ["\x1b[a", Key::Up],
            'shift Down' => ["\x1b[b", Key::Down],
            'shift Right' => ["\x1b[c", Key::Right],
            'shift Left' => ["\x1b[d", Key::Left],
        ];
    }

    #[DataProvider('rxvtShiftedArrowProvider')]
    #[Test]
    public function parseRxvtShiftedArrows(string $input, Key $expectedKey): void
    {
        $messages = InputParser::parse($input)->messages;
        self::assertCount(1, $messages);
        self::assertInstanceOf(KeyMsg::class, $messages[0]);
        self::assertSame($expectedKey, $messages[0]->key);
        self::assertTrue($messages[0]->shift);
    }

    /**
     * @return array<string, array{string, Key}>
     */
    public static function rxvtCtrlArrowProvider(): array
    {
        return [
            'ctrl Up' => ["\x1bOa", Key::Up],
            'ctrl Down' => ["\x1bOb", Key::Down],
            'ctrl Right' => ["\x1bOc", Key::Right],
            'ctrl Left' => ["\x1bOd", Key::Left],
        ];
    }

    #[DataProvider('rxvtCtrlArrowProvider')]
    #[Test]
    public function parseRxvtCtrlArrows(string $input, Key $expectedKey): void
    {
        $messages = InputParser::parse($input)->messages;
        self::assertCount(1, $messages);
        self::assertInstanceOf(KeyMsg::class, $messages[0]);
        self::assertSame($expectedKey, $messages[0]->key);
        self::assertTrue($messages[0]->ctrl);
    }

    /**
     * @return array<string, array{int, MouseAction, MouseButton}>
     */
    public static function x10MouseButtonProvider(): array
    {
        return [
            'left press' => [0, MouseAction::Press, MouseButton::Left],
            'middle press' => [1, MouseAction::Press, MouseButton::Middle],
            'right press' => [2, MouseAction::Press, MouseButton::Right],
            'release' => [3, MouseAction::Release, MouseButton::None],
            'motion' => [32, MouseAction::Motion, MouseButton::Left],
            'wheel up' => [64, MouseAction::WheelUp, MouseButton::None],
            'wheel down' => [65, MouseAction::WheelDown, MouseButton::None],
        ];
    }

    #[DataProvider('x10MouseButtonProvider')]
    #[Test]
    public function parseX10MouseReport(int $buttonCode, MouseAction $expectedAction, MouseButton $expectedButton): void
    {
        $raw = "\x1b[M" . chr(32 + $buttonCode) . chr(32 + 5) . chr(32 + 3);

        $messages = InputParser::parse($raw)->messages;

        self::assertCount(1, $messages);
        self::assertInstanceOf(MouseMsg::class, $messages[0]);
        self::assertSame(4, $messages[0]->x, 'Column is 1-based over the wire');
        self::assertSame(2, $messages[0]->y, 'Row is 1-based over the wire');
        self::assertSame($expectedAction, $messages[0]->action);
        self::assertSame($expectedButton, $messages[0]->button);
    }

    #[Test]
    public function parseX10MouseReportFollowedByKey(): void
    {
        $raw = "\x1b[M" . chr(32) . chr(33) . chr(34) . 'a';

        $messages = InputParser::parse($raw)->messages;

        self::assertCount(2, $messages);
        self::assertInstanceOf(MouseMsg::class, $messages[0]);
        self::assertInstanceOf(KeyMsg::class, $messages[1]);
        self::assertSame('a', $messages[1]->key);
    }

    #[Test]
    public function aTruncatedX10MouseReportWaitsForTheRestOfItself(): void
    {
        $raw = "\x1b[M" . chr(32) . chr(33);

        $result = InputParser::parse($raw);

        self::assertSame([], $result->messages, 'a coordinate byte may look like a final byte, so the count is what ends it');
        self::assertSame($raw, $result->remainder);
    }

    #[Test]
    public function parseX10MouseReportWithHighCoordinates(): void
    {
        $raw = "\x1b[M" . chr(32) . chr(32 + 200) . chr(32 + 100);

        $messages = InputParser::parse($raw)->messages;

        self::assertCount(1, $messages);
        self::assertInstanceOf(MouseMsg::class, $messages[0]);
        self::assertSame(199, $messages[0]->x);
        self::assertSame(99, $messages[0]->y);
    }

    #[Test]
    public function parseCtrlC(): void
    {
        $messages = InputParser::parse("\x03")->messages;
        self::assertCount(1, $messages);
        self::assertInstanceOf(KeyMsg::class, $messages[0]);
        self::assertSame('c', $messages[0]->key);
        self::assertTrue($messages[0]->ctrl);
    }

    #[Test]
    public function parseModifiedArrow(): void
    {
        $messages = InputParser::parse("\x1b[1;5A")->messages;
        self::assertCount(1, $messages);
        self::assertSame(Key::Up, $messages[0]->key);
        self::assertTrue($messages[0]->ctrl);
        self::assertFalse($messages[0]->shift);
    }

    #[Test]
    public function shiftBitParsedCorrectly(): void
    {
        $messages = InputParser::parse("\x1b[1;2A")->messages;
        self::assertCount(1, $messages);
        self::assertSame(Key::Up, $messages[0]->key);
        self::assertTrue($messages[0]->shift);
        self::assertFalse($messages[0]->ctrl);
    }

    #[Test]
    public function parseFunctionKeys(): void
    {
        $messages = InputParser::parse("\x1bOP")->messages;
        self::assertCount(1, $messages);
        self::assertSame(Key::F1, $messages[0]->key);
    }

    #[Test]
    public function parseEmptyString(): void
    {
        self::assertSame([], InputParser::parse('')->messages);
    }

    #[Test]
    public function parseBackspace(): void
    {
        $messages = InputParser::parse("\x7f")->messages;
        self::assertCount(1, $messages);
        self::assertSame(Key::Backspace, $messages[0]->key);
    }

    #[Test]
    public function parseUtf8Multibyte(): void
    {
        $messages = InputParser::parse('ü')->messages;
        self::assertCount(1, $messages);
        self::assertSame('ü', $messages[0]->key);
    }

    #[Test]
    public function parseAltKey(): void
    {
        $messages = InputParser::parse("\x1bx")->messages;
        self::assertCount(1, $messages);
        self::assertTrue($messages[0]->alt);
        self::assertSame('x', $messages[0]->key);
    }

    #[Test]
    public function parseMultipleKeysInSequence(): void
    {
        $messages = InputParser::parse('abc')->messages;
        self::assertCount(3, $messages);
        self::assertSame('a', $messages[0]->key);
        self::assertSame('b', $messages[1]->key);
        self::assertSame('c', $messages[2]->key);
    }

    #[Test]
    public function parseDelete(): void
    {
        $messages = InputParser::parse("\x1b[3~")->messages;
        self::assertCount(1, $messages);
        self::assertSame(Key::Delete, $messages[0]->key);
    }

    #[Test]
    public function parsePageUpDown(): void
    {
        $messages = InputParser::parse("\x1b[5~\x1b[6~")->messages;
        self::assertCount(2, $messages);
        self::assertSame(Key::PageUp, $messages[0]->key);
        self::assertSame(Key::PageDown, $messages[1]->key);
    }

    #[Test]
    public function parseSgrMousePress(): void
    {
        $messages = InputParser::parse("\x1b[<0;5;3M")->messages;
        self::assertCount(1, $messages);
        self::assertInstanceOf(MouseMsg::class, $messages[0]);
        self::assertSame(4, $messages[0]->x);
        self::assertSame(2, $messages[0]->y);
        self::assertSame(MouseAction::Press, $messages[0]->action);
        self::assertSame(MouseButton::Left, $messages[0]->button);
    }

    #[Test]
    public function theBackAndForwardMouseButtonsClickNothing(): void
    {
        self::assertSame([[], []], [
            InputParser::parse("\x1b[<128;10;5M")->messages,
            InputParser::parse("\x1b[<129;10;5m")->messages,
        ]);
    }

    #[Test]
    public function parseSgrMouseRelease(): void
    {
        $messages = InputParser::parse("\x1b[<0;5;3m")->messages;
        self::assertCount(1, $messages);
        self::assertSame(MouseAction::Release, $messages[0]->action);
    }

    #[Test]
    public function parseSgrWheelUp(): void
    {
        $messages = InputParser::parse("\x1b[<64;10;5M")->messages;
        self::assertCount(1, $messages);
        self::assertSame(MouseAction::WheelUp, $messages[0]->action);
    }

    #[Test]
    public function aWheelEventCarriesTheModifiersHeldWithIt(): void
    {
        // The button code adds 4 for shift, 8 for alt and 16 for ctrl on top of
        // the 64 that marks a wheel.
        $plain = InputParser::parse("\x1b[<64;10;5M")->messages[0];
        $shifted = InputParser::parse("\x1b[<68;10;5M")->messages[0];
        $ctrlAlt = InputParser::parse("\x1b[<88;10;5M")->messages[0];

        self::assertInstanceOf(MouseMsg::class, $plain);
        self::assertFalse($plain->shift);
        self::assertInstanceOf(MouseMsg::class, $shifted);
        self::assertSame(MouseAction::WheelUp, $shifted->action, 'the modifier does not change what happened');
        self::assertTrue($shifted->shift);
        self::assertInstanceOf(MouseMsg::class, $ctrlAlt);
        self::assertTrue($ctrlAlt->ctrl);
        self::assertTrue($ctrlAlt->alt);
        self::assertFalse($ctrlAlt->shift);
    }

    #[Test]
    public function parseHomeEnd(): void
    {
        $home = InputParser::parse("\x1b[H")->messages;
        self::assertSame(Key::Home, $home[0]->key);
        $end = InputParser::parse("\x1b[F")->messages;
        self::assertSame(Key::End, $end[0]->key);
    }

    #[Test]
    public function parseInsert(): void
    {
        $messages = InputParser::parse("\x1b[2~")->messages;
        self::assertCount(1, $messages);
        self::assertSame(Key::Insert, $messages[0]->key);
    }

    // ===================================================================
    // L19: ReturnRemoval — empty string must return exactly empty array
    // ===================================================================

    #[Test]
    public function parserReturnsEmptyArrayForEmptyInput(): void
    {
        $result = InputParser::parse('');
        self::assertIsArray($result->messages);
        self::assertCount(0, $result->messages);
    }

    // ===================================================================
    // L33,60,68,79,85,91,97,116: Continue_ mutations
    // Each continue ensures the parser advances past the current token
    // and doesn't fall through to produce duplicate/wrong messages.
    // ===================================================================

    #[Test]
    public function parserRecognizesEscapeAtEndOfInputAsRemainder(): void
    {
        $result = InputParser::parse("\x1b");
        self::assertSame([], $result->messages);
        self::assertSame("\x1b", $result->remainder);
    }

    #[Test]
    public function parserRecognizesSsThreeSequenceWithoutDuplicates(): void
    {
        $messages = InputParser::parse("\x1bOP")->messages;
        self::assertCount(1, $messages);
        self::assertSame(Key::F1, $messages[0]->key);
        self::assertFalse($messages[0]->alt);
        self::assertFalse($messages[0]->ctrl);
    }

    #[Test]
    public function parserRecognizesAltKeyWithoutDuplicates(): void
    {
        $messages = InputParser::parse("\x1ba")->messages;
        self::assertCount(1, $messages);
        self::assertTrue($messages[0]->alt);
        self::assertSame('a', $messages[0]->key);
    }

    #[Test]
    public function parserRecognizesTabWithoutDuplicates(): void
    {
        $messages = InputParser::parse("\t")->messages;
        self::assertCount(1, $messages);
        self::assertSame(Key::Tab, $messages[0]->key);
    }

    #[Test]
    public function parserRecognizesEnterWithoutDuplicates(): void
    {
        $messages = InputParser::parse("\r")->messages;
        self::assertCount(1, $messages);
        self::assertSame(Key::Enter, $messages[0]->key);
    }

    #[Test]
    public function parserRecognizesLinefeedAsEnterWithoutDuplicates(): void
    {
        $messages = InputParser::parse("\n")->messages;
        self::assertCount(1, $messages);
        self::assertSame(Key::Enter, $messages[0]->key);
    }

    #[Test]
    public function parserRecognizesCtrlKeyWithoutDuplicates(): void
    {
        $messages = InputParser::parse("\x01")->messages;
        self::assertCount(1, $messages);
        self::assertSame('a', $messages[0]->key);
        self::assertTrue($messages[0]->ctrl);
    }

    #[Test]
    public function parserRecognizesBackspace0x7fWithoutDuplicates(): void
    {
        $messages = InputParser::parse("\x7f")->messages;
        self::assertCount(1, $messages);
        self::assertSame(Key::Backspace, $messages[0]->key);
    }

    #[Test]
    public function parserRecognizesUtf8CharacterWithoutDuplicates(): void
    {
        $messages = InputParser::parse('ü')->messages;
        self::assertCount(1, $messages);
        self::assertSame('ü', $messages[0]->key);
    }

    // ===================================================================
    // L47-48: UTF-8 byte length detection and SS3 match arms
    // ===================================================================

    #[Test]
    public function parserRecognizesUtf8TwoByteSequence(): void
    {
        $messages = InputParser::parse("\xc3\xbc")->messages; // ü
        self::assertCount(1, $messages);
        self::assertSame('ü', $messages[0]->key);
    }

    #[Test]
    public function parserRecognizesUtf8ThreeByteSequence(): void
    {
        $messages = InputParser::parse("\xe2\x9c\x93")->messages; // ✓
        self::assertCount(1, $messages);
        self::assertSame('✓', $messages[0]->key);
    }

    #[Test]
    public function parserRecognizesUtf8FourByteSequence(): void
    {
        $emoji = "\xf0\x9f\x98\x80"; // 😀
        $messages = InputParser::parse($emoji)->messages;
        self::assertCount(1, $messages);
        self::assertSame($emoji, $messages[0]->key);
    }

    #[Test]
    public function parserRecognizesSsThreeF2(): void
    {
        $messages = InputParser::parse("\x1bOQ")->messages;
        self::assertCount(1, $messages);
        self::assertSame(Key::F2, $messages[0]->key);
    }

    #[Test]
    public function parserRecognizesSsThreeF3(): void
    {
        $messages = InputParser::parse("\x1bOR")->messages;
        self::assertCount(1, $messages);
        self::assertSame(Key::F3, $messages[0]->key);
    }

    #[Test]
    public function parserRecognizesSsThreeF4(): void
    {
        $messages = InputParser::parse("\x1bOS")->messages;
        self::assertCount(1, $messages);
        self::assertSame(Key::F4, $messages[0]->key);
    }

    #[Test]
    public function parserRecognizesSsThreeHome(): void
    {
        $messages = InputParser::parse("\x1bOH")->messages;
        self::assertCount(1, $messages);
        self::assertSame(Key::Home, $messages[0]->key);
    }

    #[Test]
    public function parserRecognizesSsThreeEnd(): void
    {
        $messages = InputParser::parse("\x1bOF")->messages;
        self::assertCount(1, $messages);
        self::assertSame(Key::End, $messages[0]->key);
    }

    #[Test]
    public function parserSsThreeUnknownFallsThroughToAlt(): void
    {
        // SS3 with unknown char: key=null, so we skip the SS3 block.
        // Then alt check: ord('O')=0x4f, in range 0x20-0x7e => alt-O produced.
        // Remaining 'Z' parsed as plain key.
        $messages = InputParser::parse("\x1bOZ")->messages;
        self::assertCount(2, $messages);
        self::assertTrue($messages[0]->alt);
        self::assertSame('O', $messages[0]->key);
        self::assertSame('Z', $messages[1]->key);
    }

    // ===================================================================
    // L59,67,82,100,108,115: Position tracking (Assignment, IncrementInteger)
    // ===================================================================

    #[Test]
    public function parserAdvancesPositionCorrectlyAfterSsThreeKey(): void
    {
        $messages = InputParser::parse("\x1bOPq")->messages;
        self::assertCount(2, $messages);
        self::assertSame(Key::F1, $messages[0]->key);
        self::assertSame('q', $messages[1]->key);
    }

    #[Test]
    public function parserAdvancesPositionCorrectlyAfterAltKey(): void
    {
        $messages = InputParser::parse("\x1baq")->messages;
        self::assertCount(2, $messages);
        self::assertTrue($messages[0]->alt);
        self::assertSame('a', $messages[0]->key);
        self::assertSame('q', $messages[1]->key);
    }

    #[Test]
    public function parserAdvancesPositionAfterEnter(): void
    {
        $messages = InputParser::parse("\rq")->messages;
        self::assertCount(2, $messages);
        self::assertSame(Key::Enter, $messages[0]->key);
        self::assertSame('q', $messages[1]->key);
    }

    #[Test]
    public function parserAdvancesPositionAfterCtrlKey(): void
    {
        $messages = InputParser::parse("\x01q")->messages;
        self::assertCount(2, $messages);
        self::assertTrue($messages[0]->ctrl);
        self::assertSame('a', $messages[0]->key);
        self::assertSame('q', $messages[1]->key);
    }

    #[Test]
    public function parserAdvancesPositionAfterBackspace0x7f(): void
    {
        $messages = InputParser::parse("\x7fq")->messages;
        self::assertCount(2, $messages);
        self::assertSame(Key::Backspace, $messages[0]->key);
        self::assertSame('q', $messages[1]->key);
    }

    #[Test]
    public function parserAdvancesPositionCorrectlyAfterUtf8TwoByte(): void
    {
        $messages = InputParser::parse('üq')->messages;
        self::assertCount(2, $messages);
        self::assertSame('ü', $messages[0]->key);
        self::assertSame('q', $messages[1]->key);
    }

    #[Test]
    public function parserAdvancesPositionCorrectlyAfterUtf8ThreeByte(): void
    {
        $messages = InputParser::parse('✓q')->messages;
        self::assertCount(2, $messages);
        self::assertSame('✓', $messages[0]->key);
        self::assertSame('q', $messages[1]->key);
    }

    #[Test]
    public function parserAdvancesPositionCorrectlyAfterUtf8FourByte(): void
    {
        $emoji = "\xf0\x9f\x98\x80";
        $messages = InputParser::parse($emoji . 'q')->messages;
        self::assertCount(2, $messages);
        self::assertSame($emoji, $messages[0]->key);
        self::assertSame('q', $messages[1]->key);
    }

    // ===================================================================
    // L65,88,106,132,138,140,145,150: Boundary comparisons
    // ===================================================================

    #[Test]
    public function parserRecognizesAltWithSpaceCharacter(): void
    {
        // L65: altByte >= 0x20 — space is exactly 0x20
        $messages = InputParser::parse("\x1b ")->messages;
        self::assertCount(1, $messages);
        self::assertTrue($messages[0]->alt);
        self::assertSame(' ', $messages[0]->key);
    }

    #[Test]
    public function parserRecognizesAltWithTilde(): void
    {
        // L65: altByte < 0x7f — tilde is 0x7e, just under boundary
        $messages = InputParser::parse("\x1b~")->messages;
        self::assertCount(1, $messages);
        self::assertTrue($messages[0]->alt);
        self::assertSame('~', $messages[0]->key);
    }

    #[Test]
    public function escapeFollowedByDeleteIsAltBackspace(): void
    {
        $messages = InputParser::parse("\x1b\x7f")->messages;
        self::assertCount(1, $messages);
        self::assertSame(Key::Backspace, $messages[0]->key);
        self::assertTrue($messages[0]->alt);
    }

    #[Test]
    public function parserRecognizesCtrlAAtLowerBound(): void
    {
        // L88: byte >= 0x01
        $messages = InputParser::parse("\x01")->messages;
        self::assertCount(1, $messages);
        self::assertSame('a', $messages[0]->key);
        self::assertTrue($messages[0]->ctrl);
    }

    #[Test]
    public function parserRecognizesCtrlZAtUpperBound(): void
    {
        // L88: byte <= 0x1a
        $messages = InputParser::parse("\x1a")->messages;
        self::assertCount(1, $messages);
        self::assertSame('z', $messages[0]->key);
        self::assertTrue($messages[0]->ctrl);
    }

    /**
     * The control bytes outside ctrl+a..ctrl+z arrived as their own raw character,
     * so ctrl+space typed a NUL into whatever had the keyboard and no binding
     * could name any of them.
     */
    #[Test]
    public function theControlBytesBesideTheLetterRangeAreNamedKeysToo(): void
    {
        foreach (["\x00" => ' ', "\x1c" => '\\', "\x1d" => ']', "\x1e" => '^', "\x1f" => '_'] as $byte => $expected) {
            // Followed by a letter, so the control byte is shown to consume itself
            // and nothing else — stopping there would swallow the rest of the read.
            $messages = InputParser::parse($byte . 'a')->messages;

            self::assertCount(2, $messages);
            self::assertSame($expected, $messages[0]->key);
            self::assertTrue($messages[0]->ctrl);
            self::assertSame('a', $messages[1]->key);
        }
    }

    /**
     * Nothing on a keyboard produces a byte that cannot begin a character, and
     * passing one on puts invalid UTF-8 into the model of whatever holds the
     * keyboard — where every UTF-8-aware call on the value then answers nothing.
     */
    #[Test]
    public function aByteThatCannotBeACharacterIsNotAKeystroke(): void
    {
        self::assertSame([], InputParser::parse("\x80")->messages, 'a continuation byte with nothing in front of it');
        self::assertSame([], InputParser::parse("\xff")->messages, 'a byte no encoder can produce');
        self::assertSame([], InputParser::parse("\xe2\x28\xa1")->messages, 'a lead byte whose sequence is malformed');
    }

    #[Test]
    public function parserCsiParameterCollectionStopsAtFinalByte0x40(): void
    {
        // L140: byte >= 0x40 — '@' is 0x40, boundary. A byte below it would still
        // be a parameter, leaving the sequence unfinished and held as a remainder.
        $result = InputParser::parse("\x1b[@");

        self::assertSame([], $result->messages);
        self::assertSame('', $result->remainder, "'@' ends the sequence rather than continuing it");
    }

    #[Test]
    public function parserCsiParameterCollectionStopsAtFinalByte0x7e(): void
    {
        // L140: byte <= 0x7e — '~' is 0x7e, boundary
        $messages = InputParser::parse("\x1b[3~")->messages;
        self::assertCount(1, $messages);
        self::assertSame(Key::Delete, $messages[0]->key);
    }

    #[Test]
    public function parserCsiDetectsMouseSequenceWithLessThan(): void
    {
        // L132: position < length && raw[position] === '<'
        $messages = InputParser::parse("\x1b[<0;1;1M")->messages;
        self::assertCount(1, $messages);
        self::assertInstanceOf(MouseMsg::class, $messages[0]);
    }

    #[Test]
    public function parserCsiIncompleteWithParameterOnlyReturnsRemainder(): void
    {
        $result = InputParser::parse("\x1b[1");
        self::assertSame([], $result->messages);
        self::assertSame("\x1b[1", $result->remainder);
    }

    #[Test]
    public function parserCsiExcessiveLengthBreaksLoop(): void
    {
        $longParams = str_repeat('1', 30);
        $result = InputParser::parse("\x1b[" . $longParams . 'A');
        self::assertIsArray($result->messages);
    }

    #[Test]
    public function parserCsiEmptyReturnsRemainder(): void
    {
        $result = InputParser::parse("\x1b[");
        self::assertSame([], $result->messages);
        self::assertSame("\x1b[", $result->remainder);
    }

    // ===================================================================
    // L157-159: FalseValue x3 — boolean modifier flags init to false
    // ===================================================================

    #[Test]
    public function parserCsiArrowWithoutModifiersHasAllFlagsFalse(): void
    {
        $messages = InputParser::parse("\x1b[A")->messages;
        self::assertCount(1, $messages);
        self::assertFalse($messages[0]->shift);
        self::assertFalse($messages[0]->alt);
        self::assertFalse($messages[0]->ctrl);
    }

    #[Test]
    public function parserCsiKeyWithParameterButNoSemicolonHasAllFlagsFalse(): void
    {
        $messages = InputParser::parse("\x1b[3~")->messages;
        self::assertCount(1, $messages);
        self::assertFalse($messages[0]->shift);
        self::assertFalse($messages[0]->alt);
        self::assertFalse($messages[0]->ctrl);
    }

    // ===================================================================
    // L163-167: CastInt, Minus, BitwiseAnd, IncrementInteger — modifier parsing
    // ===================================================================

    #[Test]
    public function parserCsiModifierShiftOnly(): void
    {
        // modifier = 2-1 = 1, shift = 1&1 = true
        $messages = InputParser::parse("\x1b[1;2A")->messages;
        self::assertCount(1, $messages);
        self::assertTrue($messages[0]->shift);
        self::assertFalse($messages[0]->alt);
        self::assertFalse($messages[0]->ctrl);
    }

    #[Test]
    public function parserCsiModifierAltOnly(): void
    {
        // modifier = 3-1 = 2, alt = 2&2 = true
        $messages = InputParser::parse("\x1b[1;3A")->messages;
        self::assertCount(1, $messages);
        self::assertFalse($messages[0]->shift);
        self::assertTrue($messages[0]->alt);
        self::assertFalse($messages[0]->ctrl);
    }

    #[Test]
    public function parserCsiModifierCtrlOnly(): void
    {
        // modifier = 5-1 = 4, ctrl = 4&4 = true
        $messages = InputParser::parse("\x1b[1;5A")->messages;
        self::assertCount(1, $messages);
        self::assertFalse($messages[0]->shift);
        self::assertFalse($messages[0]->alt);
        self::assertTrue($messages[0]->ctrl);
    }

    #[Test]
    public function parserCsiModifierShiftAlt(): void
    {
        // modifier = 4-1 = 3
        $messages = InputParser::parse("\x1b[1;4A")->messages;
        self::assertCount(1, $messages);
        self::assertTrue($messages[0]->shift);
        self::assertTrue($messages[0]->alt);
        self::assertFalse($messages[0]->ctrl);
    }

    #[Test]
    public function parserCsiModifierShiftCtrl(): void
    {
        // modifier = 6-1 = 5
        $messages = InputParser::parse("\x1b[1;6A")->messages;
        self::assertCount(1, $messages);
        self::assertTrue($messages[0]->shift);
        self::assertFalse($messages[0]->alt);
        self::assertTrue($messages[0]->ctrl);
    }

    #[Test]
    public function parserCsiModifierAltCtrl(): void
    {
        // modifier = 7-1 = 6
        $messages = InputParser::parse("\x1b[1;7A")->messages;
        self::assertCount(1, $messages);
        self::assertFalse($messages[0]->shift);
        self::assertTrue($messages[0]->alt);
        self::assertTrue($messages[0]->ctrl);
    }

    #[Test]
    public function parserCsiModifierAllThree(): void
    {
        // modifier = 8-1 = 7, all bits set
        $messages = InputParser::parse("\x1b[1;8A")->messages;
        self::assertCount(1, $messages);
        self::assertTrue($messages[0]->shift);
        self::assertTrue($messages[0]->alt);
        self::assertTrue($messages[0]->ctrl);
    }

    #[Test]
    public function parserCsiModifierValueOneIsNoModifiers(): void
    {
        // modifier = 1-1 = 0, no bits set
        $messages = InputParser::parse("\x1b[1;1A")->messages;
        self::assertCount(1, $messages);
        self::assertSame(Key::Up, $messages[0]->key);
        self::assertFalse($messages[0]->shift);
        self::assertFalse($messages[0]->alt);
        self::assertFalse($messages[0]->ctrl);
    }

    #[Test]
    public function parserCsiModifierExtractsFirstParameterCorrectly(): void
    {
        // L167: parameters = parameterParts[0] after splitting
        // \x1b[3;5~ = Ctrl-Delete
        $messages = InputParser::parse("\x1b[3;5~")->messages;
        self::assertCount(1, $messages);
        self::assertSame(Key::Delete, $messages[0]->key);
        self::assertTrue($messages[0]->ctrl);
    }

    // ===================================================================
    // L171,183: MatchArmRemoval — CSI key mapping match arms
    // ===================================================================

    #[Test]
    public function parserCsiArrowUp(): void
    {
        $messages = InputParser::parse("\x1b[A")->messages;
        self::assertCount(1, $messages);
        self::assertSame(Key::Up, $messages[0]->key);
    }

    #[Test]
    public function parserCsiArrowDown(): void
    {
        $messages = InputParser::parse("\x1b[B")->messages;
        self::assertCount(1, $messages);
        self::assertSame(Key::Down, $messages[0]->key);
    }

    #[Test]
    public function parserCsiArrowRight(): void
    {
        $messages = InputParser::parse("\x1b[C")->messages;
        self::assertCount(1, $messages);
        self::assertSame(Key::Right, $messages[0]->key);
    }

    #[Test]
    public function parserCsiArrowLeft(): void
    {
        $messages = InputParser::parse("\x1b[D")->messages;
        self::assertCount(1, $messages);
        self::assertSame(Key::Left, $messages[0]->key);
    }

    #[Test]
    public function parserCsiHome(): void
    {
        $messages = InputParser::parse("\x1b[H")->messages;
        self::assertCount(1, $messages);
        self::assertSame(Key::Home, $messages[0]->key);
    }

    #[Test]
    public function parserCsiEnd(): void
    {
        $messages = InputParser::parse("\x1b[F")->messages;
        self::assertCount(1, $messages);
        self::assertSame(Key::End, $messages[0]->key);
    }

    #[Test]
    public function parserCsiShiftTabBackTab(): void
    {
        $messages = InputParser::parse("\x1b[Z")->messages;
        self::assertCount(1, $messages);
        self::assertSame(Key::Tab, $messages[0]->key);
        self::assertTrue($messages[0]->shift);
    }

    #[Test]
    public function parserCsiF1(): void
    {
        $messages = InputParser::parse("\x1b[P")->messages;
        self::assertCount(1, $messages);
        self::assertSame(Key::F1, $messages[0]->key);
    }

    #[Test]
    public function parserCsiF2(): void
    {
        $messages = InputParser::parse("\x1b[Q")->messages;
        self::assertCount(1, $messages);
        self::assertSame(Key::F2, $messages[0]->key);
    }

    #[Test]
    public function parserCsiF3(): void
    {
        $messages = InputParser::parse("\x1b[R")->messages;
        self::assertCount(1, $messages);
        self::assertSame(Key::F3, $messages[0]->key);
    }

    #[Test]
    public function parserCsiF4(): void
    {
        $messages = InputParser::parse("\x1b[S")->messages;
        self::assertCount(1, $messages);
        self::assertSame(Key::F4, $messages[0]->key);
    }

    #[Test]
    public function parserCsiInsert(): void
    {
        $messages = InputParser::parse("\x1b[2~")->messages;
        self::assertCount(1, $messages);
        self::assertSame(Key::Insert, $messages[0]->key);
    }

    #[Test]
    public function parserCsiDelete(): void
    {
        $messages = InputParser::parse("\x1b[3~")->messages;
        self::assertCount(1, $messages);
        self::assertSame(Key::Delete, $messages[0]->key);
    }

    #[Test]
    public function parserCsiPageUp(): void
    {
        $messages = InputParser::parse("\x1b[5~")->messages;
        self::assertCount(1, $messages);
        self::assertSame(Key::PageUp, $messages[0]->key);
    }

    #[Test]
    public function parserCsiPageDown(): void
    {
        $messages = InputParser::parse("\x1b[6~")->messages;
        self::assertCount(1, $messages);
        self::assertSame(Key::PageDown, $messages[0]->key);
    }

    #[Test]
    public function parserCsiF5(): void
    {
        $messages = InputParser::parse("\x1b[15~")->messages;
        self::assertCount(1, $messages);
        self::assertSame(Key::F5, $messages[0]->key);
    }

    #[Test]
    public function parserCsiF6(): void
    {
        $messages = InputParser::parse("\x1b[17~")->messages;
        self::assertCount(1, $messages);
        self::assertSame(Key::F6, $messages[0]->key);
    }

    #[Test]
    public function parserCsiF7(): void
    {
        $messages = InputParser::parse("\x1b[18~")->messages;
        self::assertCount(1, $messages);
        self::assertSame(Key::F7, $messages[0]->key);
    }

    #[Test]
    public function parserCsiF8(): void
    {
        $messages = InputParser::parse("\x1b[19~")->messages;
        self::assertCount(1, $messages);
        self::assertSame(Key::F8, $messages[0]->key);
    }

    #[Test]
    public function parserCsiF9(): void
    {
        $messages = InputParser::parse("\x1b[20~")->messages;
        self::assertCount(1, $messages);
        self::assertSame(Key::F9, $messages[0]->key);
    }

    #[Test]
    public function parserCsiF10(): void
    {
        $messages = InputParser::parse("\x1b[21~")->messages;
        self::assertCount(1, $messages);
        self::assertSame(Key::F10, $messages[0]->key);
    }

    #[Test]
    public function parserCsiF11(): void
    {
        $messages = InputParser::parse("\x1b[23~")->messages;
        self::assertCount(1, $messages);
        self::assertSame(Key::F11, $messages[0]->key);
    }

    #[Test]
    public function parserCsiF12(): void
    {
        $messages = InputParser::parse("\x1b[24~")->messages;
        self::assertCount(1, $messages);
        self::assertSame(Key::F12, $messages[0]->key);
    }

    /**
     * A terminal answers questions on the stream the reader types on, so a
     * complete sequence this parser has no key for is the terminal talking —
     * spelling it out as alt+[ and its parameter bytes types the answer into
     * whatever had the keyboard.
     */
    #[Test]
    public function aCompleteCsiSequenceItHasNoKeyForIsNotTypedText(): void
    {
        self::assertSame([], InputParser::parse("\x1b[99~")->messages, 'a parameter it does not know');
        self::assertSame([], InputParser::parse("\x1b[x")->messages, 'a final byte it does not know');
        self::assertSame([], InputParser::parse("\x1b[24;80R")->messages, 'a cursor position report');
    }

    // ===================================================================
    // L205-207: Shift-Tab (BackTab) special case
    // ===================================================================

    #[Test]
    public function parserCsiBackTabAlwaysSetsShift(): void
    {
        $messages = InputParser::parse("\x1b[Z")->messages;
        self::assertCount(1, $messages);
        self::assertSame(Key::Tab, $messages[0]->key);
        self::assertTrue($messages[0]->shift);
        self::assertFalse($messages[0]->ctrl);
        self::assertFalse($messages[0]->alt);
    }

    // ===================================================================
    // L216-256: Mouse parsing mutations
    // ===================================================================

    #[Test]
    public function parserSgrMousePressLeftButton(): void
    {
        $messages = InputParser::parse("\x1b[<0;10;20M")->messages;
        self::assertCount(1, $messages);
        self::assertInstanceOf(MouseMsg::class, $messages[0]);
        self::assertSame(9, $messages[0]->x);
        self::assertSame(19, $messages[0]->y);
        self::assertSame(MouseAction::Press, $messages[0]->action);
        self::assertSame(MouseButton::Left, $messages[0]->button);
    }

    #[Test]
    public function parserSgrMousePressMiddleButton(): void
    {
        $messages = InputParser::parse("\x1b[<1;10;20M")->messages;
        self::assertCount(1, $messages);
        self::assertInstanceOf(MouseMsg::class, $messages[0]);
        self::assertSame(MouseAction::Press, $messages[0]->action);
        self::assertSame(MouseButton::Middle, $messages[0]->button);
    }

    #[Test]
    public function parserSgrMousePressRightButton(): void
    {
        $messages = InputParser::parse("\x1b[<2;10;20M")->messages;
        self::assertCount(1, $messages);
        self::assertInstanceOf(MouseMsg::class, $messages[0]);
        self::assertSame(MouseAction::Press, $messages[0]->action);
        self::assertSame(MouseButton::Right, $messages[0]->button);
    }

    #[Test]
    public function parserSgrMouseReleaseLeftButton(): void
    {
        $messages = InputParser::parse("\x1b[<0;5;3m")->messages;
        self::assertCount(1, $messages);
        self::assertInstanceOf(MouseMsg::class, $messages[0]);
        self::assertSame(MouseAction::Release, $messages[0]->action);
        self::assertSame(MouseButton::Left, $messages[0]->button);
    }

    #[Test]
    public function parserSgrMouseReleaseRightButton(): void
    {
        $messages = InputParser::parse("\x1b[<2;5;3m")->messages;
        self::assertCount(1, $messages);
        self::assertSame(MouseAction::Release, $messages[0]->action);
        self::assertSame(MouseButton::Right, $messages[0]->button);
    }

    #[Test]
    public function parserSgrMouseMotionLeftButton(): void
    {
        // buttonCode 32 = 32(motion) + 0(left)
        $messages = InputParser::parse("\x1b[<32;10;20M")->messages;
        self::assertCount(1, $messages);
        self::assertInstanceOf(MouseMsg::class, $messages[0]);
        self::assertSame(MouseAction::Motion, $messages[0]->action);
        self::assertSame(MouseButton::Left, $messages[0]->button);
    }

    #[Test]
    public function parserSgrMouseMotionRightButton(): void
    {
        // buttonCode 34 = 32(motion) + 2(right)
        $messages = InputParser::parse("\x1b[<34;10;20M")->messages;
        self::assertCount(1, $messages);
        self::assertSame(MouseAction::Motion, $messages[0]->action);
        self::assertSame(MouseButton::Right, $messages[0]->button);
    }

    #[Test]
    public function parserSgrMouseMotionNoButton(): void
    {
        // buttonCode 35 = 32(motion) + 3(none)
        $messages = InputParser::parse("\x1b[<35;10;20M")->messages;
        self::assertCount(1, $messages);
        self::assertSame(MouseAction::Motion, $messages[0]->action);
        self::assertSame(MouseButton::None, $messages[0]->button);
    }

    #[Test]
    public function parserSgrMouseWheelUp(): void
    {
        // buttonCode 64
        $messages = InputParser::parse("\x1b[<64;10;20M")->messages;
        self::assertCount(1, $messages);
        self::assertSame(MouseAction::WheelUp, $messages[0]->action);
        self::assertSame(MouseButton::None, $messages[0]->button);
    }

    #[Test]
    public function parserSgrMouseWheelDown(): void
    {
        // buttonCode 65 = 64 + 1
        $messages = InputParser::parse("\x1b[<65;10;20M")->messages;
        self::assertCount(1, $messages);
        self::assertSame(MouseAction::WheelDown, $messages[0]->action);
        self::assertSame(MouseButton::None, $messages[0]->button);
    }

    #[Test]
    public function horizontalWheelCodesDecodeAsHorizontalWheels(): void
    {
        // buttonCode 66 = 64 + 2, 67 = 64 + 3
        $left = InputParser::parse("\x1b[<66;10;20M")->messages;
        self::assertInstanceOf(MouseMsg::class, $left[0]);
        self::assertSame(MouseAction::WheelLeft, $left[0]->action);
        self::assertTrue($left[0]->isWheel());

        $right = InputParser::parse("\x1b[<67;10;20M")->messages;
        self::assertInstanceOf(MouseMsg::class, $right[0]);
        self::assertSame(MouseAction::WheelRight, $right[0]->action);
    }

    #[Test]
    public function parserSgrMouseColumnAndRowAreZeroIndexed(): void
    {
        $messages = InputParser::parse("\x1b[<0;1;1M")->messages;
        self::assertCount(1, $messages);
        self::assertSame(0, $messages[0]->x);
        self::assertSame(0, $messages[0]->y);
    }

    #[Test]
    public function parserSgrMouseIncompleteSequenceReturnsRemainder(): void
    {
        $result = InputParser::parse("\x1b[<0;5;3");
        self::assertSame([], $result->messages);
        self::assertSame("\x1b[<0;5;3", $result->remainder);
    }

    #[Test]
    public function anSgrMouseReportWithTooFewPartsIsDropped(): void
    {
        self::assertSame([], InputParser::parse("\x1b[<0;5M")->messages);
    }

    #[Test]
    public function anSgrMouseReportWithTooManyPartsIsDropped(): void
    {
        self::assertSame([], InputParser::parse("\x1b[<0;5;3;1M")->messages);
    }

    #[Test]
    public function anOverlongSgrMouseReportIsDropped(): void
    {
        $longParams = str_repeat('1', 40);

        self::assertSame([], InputParser::parse("\x1b[<" . $longParams . 'M')->messages);
    }

    #[Test]
    public function parserSgrMousePositionIsCorrectlyParsed(): void
    {
        $messages = InputParser::parse("\x1b[<0;120;50M")->messages;
        self::assertCount(1, $messages);
        self::assertSame(119, $messages[0]->x);
        self::assertSame(49, $messages[0]->y);
    }

    // ===================================================================
    // Continue correctness: ensure parser doesn't double-produce
    // ===================================================================

    #[Test]
    public function parserHandlesSequenceOfDifferentTokenTypes(): void
    {
        $input = "\x1b\t\r\x03" . 'a' . "\x7f";
        $messages = InputParser::parse($input)->messages;
        self::assertCount(5, $messages);
        self::assertSame(Key::Tab, $messages[0]->key);
        self::assertTrue($messages[0]->alt);
        self::assertSame(Key::Enter, $messages[1]->key);
        self::assertSame('c', $messages[2]->key);
        self::assertTrue($messages[2]->ctrl);
        self::assertSame('a', $messages[3]->key);
        self::assertSame(Key::Backspace, $messages[4]->key);
    }

    #[Test]
    public function parserHandlesSequenceOfCsiKeysFollowedByPlainChars(): void
    {
        $input = "\x1b[A\x1b[Bx";
        $messages = InputParser::parse($input)->messages;
        self::assertCount(3, $messages);
        self::assertSame(Key::Up, $messages[0]->key);
        self::assertSame(Key::Down, $messages[1]->key);
        self::assertSame('x', $messages[2]->key);
    }

    #[Test]
    public function parserHandlesMouseFollowedByKeypress(): void
    {
        $input = "\x1b[<0;5;3Mq";
        $messages = InputParser::parse($input)->messages;
        self::assertCount(2, $messages);
        self::assertInstanceOf(MouseMsg::class, $messages[0]);
        self::assertInstanceOf(KeyMsg::class, $messages[1]);
        self::assertSame('q', $messages[1]->key);
    }

    // ===================================================================
    // Ctrl key character mapping (chr($byte + 0x60))
    // ===================================================================

    #[Test]
    public function parserMapsCtrlBytesToCorrectCharacters(): void
    {
        $messages = InputParser::parse("\x02")->messages;
        self::assertSame('b', $messages[0]->key);
        self::assertTrue($messages[0]->ctrl);

        $messages = InputParser::parse("\x04")->messages;
        self::assertSame('d', $messages[0]->key);
        self::assertTrue($messages[0]->ctrl);

        $messages = InputParser::parse("\x0c")->messages;
        self::assertSame('l', $messages[0]->key);
        self::assertTrue($messages[0]->ctrl);
    }

    // ===================================================================
    // 0x08: terminals commonly send this byte for Backspace
    // ===================================================================

    #[Test]
    public function parser0x08IsBackspace(): void
    {
        $messages = InputParser::parse("\x08")->messages;
        self::assertCount(1, $messages);
        self::assertSame(Key::Backspace, $messages[0]->key);
        self::assertFalse($messages[0]->ctrl);
    }

    // ===================================================================
    // SS3 sequence boundary: position + 2 < length
    // ===================================================================

    #[Test]
    public function parserEscOAtEndOfInputReturnsRemainder(): void
    {
        $result = InputParser::parse("\x1bO");
        self::assertSame([], $result->messages);
        self::assertSame("\x1bO", $result->remainder);
    }

    // ===================================================================
    // Return value from parse() is always an array (L123)
    // ===================================================================

    #[Test]
    public function parserReturnsArrayFromNonEmptyInput(): void
    {
        $result = InputParser::parse('a')->messages;
        self::assertIsArray($result);
        self::assertNotEmpty($result);
    }

    // ===================================================================
    // CSI with modifier applied to tilde-keys
    // ===================================================================

    #[Test]
    public function parserCsiModifiedInsert(): void
    {
        $messages = InputParser::parse("\x1b[2;2~")->messages;
        self::assertCount(1, $messages);
        self::assertSame(Key::Insert, $messages[0]->key);
        self::assertTrue($messages[0]->shift);
    }

    #[Test]
    public function parserCsiModifiedPageUp(): void
    {
        $messages = InputParser::parse("\x1b[5;5~")->messages;
        self::assertCount(1, $messages);
        self::assertSame(Key::PageUp, $messages[0]->key);
        self::assertTrue($messages[0]->ctrl);
    }

    #[Test]
    public function parserCsiModifiedF5(): void
    {
        $messages = InputParser::parse("\x1b[15;3~")->messages;
        self::assertCount(1, $messages);
        self::assertSame(Key::F5, $messages[0]->key);
        self::assertTrue($messages[0]->alt);
    }

    // ===================================================================
    // ESC followed by non-printable bytes that don't match alt range
    // ===================================================================

    #[Test]
    public function aControlByteAfterEscapeIsItsAltCombo(): void
    {
        $messages = InputParser::parse("\x1b\x01")->messages;
        self::assertCount(1, $messages);
        self::assertSame('a', $messages[0]->key);
        self::assertTrue($messages[0]->ctrl);
        self::assertTrue($messages[0]->alt);

        $enter = InputParser::parseSequence("\x1b\r");
        self::assertInstanceOf(KeyMsg::class, $enter);
        self::assertSame(Key::Enter, $enter->key);
        self::assertTrue($enter->alt, 'legacy alt+Enter is one key, not an escape swallowing Enter');
    }

    /**
     * @return iterable<string, array{string, KeyMsg}>
     */
    public static function altControlComboProvider(): iterable
    {
        yield 'ctrl+alt+z, the last letter' => ["\x1b\x1a", new KeyMsg('z', ctrl: true, alt: true)];
        yield 'ctrl+alt+g, below backspace' => ["\x1b\x07", new KeyMsg('g', ctrl: true, alt: true)];
        yield 'alt+backspace as BS' => ["\x1b\x08", new KeyMsg(Key::Backspace, alt: true)];
        yield 'alt+enter as LF' => ["\x1b\x0a", new KeyMsg(Key::Enter, alt: true)];
        yield 'ctrl+alt+k, above LF' => ["\x1b\x0b", new KeyMsg('k', ctrl: true, alt: true)];
    }

    #[DataProvider('altControlComboProvider')]
    #[Test]
    public function aControlByteAfterEscapeNamesItsKeyWithAlt(string $input, KeyMsg $expected): void
    {
        self::assertEquals([$expected], InputParser::parse($input)->messages);
    }

    /**
     * @return iterable<string, array{int, bool, bool, bool}>
     */
    public static function mouseModifierProvider(): iterable
    {
        yield 'none, middle button' => [1, false, false, false];
        yield 'shift' => [1 | 4, true, false, false];
        yield 'alt' => [1 | 8, false, true, false];
        yield 'ctrl' => [1 | 16, false, false, true];
    }

    #[DataProvider('mouseModifierProvider')]
    #[Test]
    public function bothMouseEncodingsCarryEachModifierOnItsOwnBit(int $buttonCode, bool $shift, bool $alt, bool $ctrl): void
    {
        $x10 = InputParser::parse("\x1b[M" . chr(32 + $buttonCode) . '!!')->messages;
        $sgr = InputParser::parse("\x1b[<{$buttonCode};1;1M")->messages;

        $expected = [new MouseMsg(0, 0, MouseAction::Press, MouseButton::Middle, shift: $shift, alt: $alt, ctrl: $ctrl)];
        self::assertEquals($expected, $x10);
        self::assertEquals($expected, $sgr);
    }

    #[Test]
    public function anSgrMouseReportWithAnEmptyFieldIsDropped(): void
    {
        self::assertSame([], InputParser::parse("\x1b[<0;;5M")->messages);
        self::assertSame([], InputParser::parse("\x1b[<;5;5M")->messages);
    }

    // ===================================================================
    // CSI continue after successful parse (L43)
    // ===================================================================

    #[Test]
    public function parserCsiContinueDoesNotFallThroughToAlt(): void
    {
        $messages = InputParser::parse("\x1b[A")->messages;
        self::assertCount(1, $messages);
        self::assertSame(Key::Up, $messages[0]->key);
        self::assertFalse($messages[0]->alt);
    }

    // ===================================================================
    // Bare escape edge cases
    // ===================================================================

    #[Test]
    public function parserEscapeFollowedByEscapeProducesEscapeAndRemainder(): void
    {
        $result = InputParser::parse("\x1b\x1b");
        self::assertCount(1, $result->messages);
        self::assertSame(Key::Escape, $result->messages[0]->key);
        self::assertSame("\x1b", $result->remainder);
    }

    // ===================================================================
    // Mouse button match default arm (buttonBase=3)
    // ===================================================================

    #[Test]
    public function parserSgrMousePressButtonBaseThreeIsNone(): void
    {
        // buttonCode 3 => buttonBase=3 => None
        $messages = InputParser::parse("\x1b[<3;5;3M")->messages;
        self::assertCount(1, $messages);
        self::assertSame(MouseButton::None, $messages[0]->button);
        self::assertSame(MouseAction::Press, $messages[0]->action);
    }

    // ===================================================================
    // CSI mouse vs non-mouse distinguishment
    // ===================================================================

    #[Test]
    public function parserCsiWithoutLessThanParsesAsKeySequence(): void
    {
        $messages = InputParser::parse("\x1b[A")->messages;
        self::assertCount(1, $messages);
        self::assertInstanceOf(KeyMsg::class, $messages[0]);
    }

    // ===================================================================
    // Printable ASCII characters
    // ===================================================================

    #[Test]
    public function parserRecognizesPrintableAsciiCharacters(): void
    {
        $messages = InputParser::parse('Z')->messages;
        self::assertCount(1, $messages);
        self::assertSame('Z', $messages[0]->key);
        self::assertFalse($messages[0]->ctrl);
        self::assertFalse($messages[0]->alt);
        self::assertFalse($messages[0]->shift);
    }

    #[Test]
    public function parserRecognizesSpaceCharacter(): void
    {
        $messages = InputParser::parse(' ')->messages;
        self::assertCount(1, $messages);
        self::assertSame(' ', $messages[0]->key);
    }

    // ===================================================================
    // Multiple UTF-8 characters in sequence
    // ===================================================================

    #[Test]
    public function parserHandlesMultipleUtf8CharactersInSequence(): void
    {
        $messages = InputParser::parse('äöü')->messages;
        self::assertCount(3, $messages);
        self::assertSame('ä', $messages[0]->key);
        self::assertSame('ö', $messages[1]->key);
        self::assertSame('ü', $messages[2]->key);
    }

    // ===================================================================
    // Mouse wheel with motion bit
    // ===================================================================

    #[Test]
    public function parserSgrMouseWheelUpWithMotion(): void
    {
        // buttonCode 96 = 64(wheel) + 32(motion), buttonBase = 0 => WheelUp
        $messages = InputParser::parse("\x1b[<96;10;20M")->messages;
        self::assertCount(1, $messages);
        self::assertSame(MouseAction::WheelUp, $messages[0]->action);
    }

    // ===================================================================
    // Mouse release middle button
    // ===================================================================

    #[Test]
    public function parserSgrMouseReleaseMiddleButton(): void
    {
        $messages = InputParser::parse("\x1b[<1;5;3m")->messages;
        self::assertCount(1, $messages);
        self::assertSame(MouseAction::Release, $messages[0]->action);
        self::assertSame(MouseButton::Middle, $messages[0]->button);
    }

    // ===================================================================
    // CSI position advancement after complete parse
    // ===================================================================

    #[Test]
    public function parserCsiAdvancesPositionPastEntireSequence(): void
    {
        $messages = InputParser::parse("\x1b[Hq")->messages;
        self::assertCount(2, $messages);
        self::assertSame(Key::Home, $messages[0]->key);
        self::assertSame('q', $messages[1]->key);
    }

    #[Test]
    public function parserCsiTildeKeyAdvancesPositionCorrectly(): void
    {
        $messages = InputParser::parse("\x1b[3~q")->messages;
        self::assertCount(2, $messages);
        self::assertSame(Key::Delete, $messages[0]->key);
        self::assertSame('q', $messages[1]->key);
    }

    #[Test]
    public function parserMouseAdvancesPositionPastEntireSequence(): void
    {
        $messages = InputParser::parse("\x1b[<0;1;1Mq")->messages;
        self::assertCount(2, $messages);
        self::assertInstanceOf(MouseMsg::class, $messages[0]);
        self::assertSame('q', $messages[1]->key);
    }

    // ===================================================================
    // SS3 with all known keys
    // ===================================================================

    #[Test]
    public function parserSsThreeWithAllKnownKeysProducesCorrectKeys(): void
    {
        $mapping = [
            'P' => Key::F1,
            'Q' => Key::F2,
            'R' => Key::F3,
            'S' => Key::F4,
            'H' => Key::Home,
            'F' => Key::End,
        ];
        foreach ($mapping as $character => $expected) {
            $messages = InputParser::parse("\x1bO" . $character)->messages;
            self::assertCount(1, $messages, "SS3 {$character} should produce exactly one message");
            self::assertSame($expected, $messages[0]->key, "SS3 {$character} should produce " . $expected->name);
        }
    }

    // ===================================================================
    // Modified function keys via CSI
    // ===================================================================

    #[Test]
    public function parserCsiModifiedF12(): void
    {
        $messages = InputParser::parse("\x1b[24;2~")->messages;
        self::assertCount(1, $messages);
        self::assertSame(Key::F12, $messages[0]->key);
        self::assertTrue($messages[0]->shift);
    }

    // ===================================================================
    // ESC + CSI with no key of ours: dropped whole, and nothing else with it
    // ===================================================================

    #[Test]
    public function aKeystrokeAfterASequenceItHasNoKeyForStillArrives(): void
    {
        $messages = InputParser::parse("\x1b[99~a")->messages;

        self::assertCount(1, $messages);
        self::assertSame('a', $messages[0]->key);
    }

    // ===================================================================
    // Ensure mouse coordinates subtraction (-1) is correct
    // ===================================================================

    #[Test]
    public function parserSgrMouseCoordinateSubtractionIsExactlyOne(): void
    {
        // SGR coords are 1-based, parser subtracts 1 to make 0-based
        // Column 5 => x=4, Row 3 => y=2
        $messages = InputParser::parse("\x1b[<0;5;3M")->messages;
        self::assertCount(1, $messages);
        self::assertSame(4, $messages[0]->x);
        self::assertSame(2, $messages[0]->y);

        // Column 1 => x=0, Row 1 => y=0
        $messages = InputParser::parse("\x1b[<0;1;1M")->messages;
        self::assertSame(0, $messages[0]->x);
        self::assertSame(0, $messages[0]->y);

        // Column 100 => x=99, Row 50 => y=49
        $messages = InputParser::parse("\x1b[<0;100;50M")->messages;
        self::assertSame(99, $messages[0]->x);
        self::assertSame(49, $messages[0]->y);
    }

    // ===================================================================
    // Motion flag detection (bit 32)
    // ===================================================================

    #[Test]
    public function parserSgrMouseMotionFlagDetected(): void
    {
        // Without motion (code 0): Press
        $press = InputParser::parse("\x1b[<0;1;1M")->messages;
        self::assertSame(MouseAction::Press, $press[0]->action);

        // With motion (code 32): Motion
        $motion = InputParser::parse("\x1b[<32;1;1M")->messages;
        self::assertSame(MouseAction::Motion, $motion[0]->action);
    }

    // ===================================================================
    // Wheel bit detection (bit 64) distinguishes wheel from non-wheel
    // ===================================================================

    #[Test]
    public function parserSgrMouseWheelBitDistinguishesFromRegularPress(): void
    {
        // Code 0 (no bit 64): regular press
        $press = InputParser::parse("\x1b[<0;1;1M")->messages;
        self::assertSame(MouseAction::Press, $press[0]->action);

        // Code 64 (bit 64 set): wheel
        $wheel = InputParser::parse("\x1b[<64;1;1M")->messages;
        self::assertSame(MouseAction::WheelUp, $wheel[0]->action);
    }

    // ===================================================================
    // isRelease flag: 'm' vs 'M'
    // ===================================================================

    #[Test]
    public function parserSgrMouseDistinguishesPressFromRelease(): void
    {
        $press = InputParser::parse("\x1b[<0;1;1M")->messages;
        self::assertSame(MouseAction::Press, $press[0]->action);

        $release = InputParser::parse("\x1b[<0;1;1m")->messages;
        self::assertSame(MouseAction::Release, $release[0]->action);
    }

    // ===================================================================
    // Multiple mouse events in sequence
    // ===================================================================

    #[Test]
    public function parserHandlesMultipleMouseEventsInSequence(): void
    {
        $input = "\x1b[<0;1;1M\x1b[<0;2;2m";
        $messages = InputParser::parse($input)->messages;
        self::assertCount(2, $messages);
        self::assertInstanceOf(MouseMsg::class, $messages[0]);
        self::assertSame(MouseAction::Press, $messages[0]->action);
        self::assertSame(0, $messages[0]->x);
        self::assertSame(0, $messages[0]->y);
        self::assertInstanceOf(MouseMsg::class, $messages[1]);
        self::assertSame(MouseAction::Release, $messages[1]->action);
        self::assertSame(1, $messages[1]->x);
        self::assertSame(1, $messages[1]->y);
    }

    // ===================================================================
    // buttonBase extraction (& 3)
    // ===================================================================

    #[Test]
    public function parserSgrMouseButtonBaseMasking(): void
    {
        // Code 0 & 3 = 0 => Left
        $messages = InputParser::parse("\x1b[<0;1;1M")->messages;
        self::assertSame(MouseButton::Left, $messages[0]->button);

        // Code 1 & 3 = 1 => Middle
        $messages = InputParser::parse("\x1b[<1;1;1M")->messages;
        self::assertSame(MouseButton::Middle, $messages[0]->button);

        // Code 2 & 3 = 2 => Right
        $messages = InputParser::parse("\x1b[<2;1;1M")->messages;
        self::assertSame(MouseButton::Right, $messages[0]->button);

        // Code 3 & 3 = 3 => None (default)
        $messages = InputParser::parse("\x1b[<3;1;1M")->messages;
        self::assertSame(MouseButton::None, $messages[0]->button);
    }

    // ===================================================================
    // CSI parameter collection: bytes 0x30-0x3f are valid param bytes
    // ===================================================================

    #[Test]
    public function parserCsiCollectsParametersBelowFinalRange(): void
    {
        // '0'-'9' (0x30-0x39) and ';' (0x3b) are parameter bytes
        // They should be collected, not treated as final bytes
        $messages = InputParser::parse("\x1b[15~")->messages;
        self::assertCount(1, $messages);
        self::assertSame(Key::F5, $messages[0]->key);
    }

    // ===================================================================
    // Escape at end vs escape-then-something: L30 boundary
    // ===================================================================

    #[Test]
    public function parserEscAtEndOfBufferIsRemainder(): void
    {
        $result = InputParser::parse("\x1b");
        self::assertSame([], $result->messages);
        self::assertSame("\x1b", $result->remainder);
    }

    #[Test]
    public function parserEscNotAtEndChecksNextByte(): void
    {
        // position + 1 < length => checks next byte
        $messages = InputParser::parse("\x1ba")->messages;
        self::assertCount(1, $messages);
        self::assertTrue($messages[0]->alt);
        self::assertSame('a', $messages[0]->key);
    }

    // ===================================================================
    // L72-73: fallthrough to bare escape when ESC + next doesn't match
    // ===================================================================

    #[Test]
    public function parserEscFollowedByHighByteProducesEscape(): void
    {
        // ESC + 0x80: fails '[' check, fails 'O' check, fails alt check (0x80 >= 0x7f)
        // => bare Escape, and 0x80 begins no character of its own
        $messages = InputParser::parse("\x1b\x80")->messages;
        self::assertCount(1, $messages);
        self::assertSame(Key::Escape, $messages[0]->key);
    }

    // ===================================================================
    // L59: SS3 position advance uses += not = (kills Assignment mutant)
    // ===================================================================

    #[Test]
    public function parserSsThreeNotAtStartAdvancesPositionCorrectly(): void
    {
        $messages = InputParser::parse("q\x1bOP")->messages;
        self::assertCount(2, $messages);
        self::assertSame('q', $messages[0]->key);
        self::assertSame(Key::F1, $messages[1]->key);
    }

    // ===================================================================
    // L67: Alt key position advance uses += not = (kills Assignment mutant)
    // ===================================================================

    #[Test]
    public function parserAltKeyNotAtStartAdvancesPositionCorrectly(): void
    {
        $messages = InputParser::parse("q\x1ba")->messages;
        self::assertCount(2, $messages);
        self::assertSame('q', $messages[0]->key);
        self::assertTrue($messages[1]->alt);
        self::assertSame('a', $messages[1]->key);
    }

    // ===================================================================
    // L109: UTF-8 3-byte mask 0xf0 vs 241 (kills IncrementInteger mutant)
    // ===================================================================

    #[Test]
    public function parserUtf8ThreeByteWithLeadByteEf(): void
    {
        // U+FFFD = \xef\xbf\xbd — lead byte 0xef
        // 0xef & 0xf0 = 0xe0 (match), but 0xef & 241 = 0xe1 (no match)
        $character = "\xef\xbf\xbd";
        $messages = InputParser::parse($character . 'q')->messages;
        self::assertCount(2, $messages);
        self::assertSame($character, $messages[0]->key);
        self::assertSame('q', $messages[1]->key);
    }

    // ===================================================================
    // L110: UTF-8 4-byte mask 0xf8 vs 247/249 (kills De/IncrementInteger)
    // ===================================================================

    #[Test]
    public function parserUtf8FourByteWithLeadByteF4(): void
    {
        // U+100000 = \xf4\x80\x80\x80 — lead byte 0xf4
        // 0xf4 & 0xf8 = 0xf0 (match), but 0xf4 & 247 = 0xf4 (no match)
        $character = "\xf4\x80\x80\x80";
        $messages = InputParser::parse($character . 'q')->messages;
        self::assertCount(2, $messages);
        self::assertSame($character, $messages[0]->key);
        self::assertSame('q', $messages[1]->key);
    }

    #[Test]
    public function parserUtf8FourByteWithLeadByteF1(): void
    {
        // U+40000 = \xf1\x80\x80\x80 — lead byte 0xf1
        // 0xf1 & 0xf8 = 0xf0 (match), but 0xf1 & 249 = 0xf1 (no match)
        $character = "\xf1\x80\x80\x80";
        $messages = InputParser::parse($character . 'q')->messages;
        self::assertCount(2, $messages);
        self::assertSame($character, $messages[0]->key);
        self::assertSame('q', $messages[1]->key);
    }

    // ===================================================================
    // L111: UTF-8 default => 1 vs 2 (kills IncrementInteger mutant)
    // ===================================================================

    #[Test]
    public function parserInvalidUtf8ContinuationByteConsumesOnlyOneByte(): void
    {
        // 0x80 hits default => 1; if default were 2, 'q' would be dropped with it
        $messages = InputParser::parse("\x80q")->messages;
        self::assertCount(1, $messages);
        self::assertSame('q', $messages[0]->key);
    }

    // ===================================================================
    // L140: CSI final byte >= 0x40 boundary (kills GreaterThanOrEqualTo)
    // ===================================================================

    #[Test]
    public function parserCsiAtSignAsFinalByteIsDetectedAtExactBoundary(): void
    {
        // '@' is 0x40 — with >= 0x40 it ends the sequence, leaving 'A' a plain
        // letter. With > 0x40 it would be a parameter and 'A' the final byte,
        // turning a typed capital into an arrow key.
        $messages = InputParser::parse("\x1b[@A")->messages;

        self::assertCount(1, $messages);
        self::assertSame('A', $messages[0]->key);
    }

    // ===================================================================
    // InputParseResult return type and remainder handling
    // ===================================================================

    #[Test]
    public function parseReturnsInputParseResult(): void
    {
        $result = InputParser::parse('a');
        self::assertInstanceOf(InputParseResult::class, $result);
    }

    #[Test]
    public function completeInputHasEmptyRemainder(): void
    {
        $result = InputParser::parse('hello');
        self::assertCount(5, $result->messages);
        self::assertSame('', $result->remainder);
    }

    #[Test]
    public function splitEscapeReturnsRemainder(): void
    {
        $result = InputParser::parse("a\x1b");
        self::assertCount(1, $result->messages);
        self::assertSame('a', $result->messages[0]->key);
        self::assertSame("\x1b", $result->remainder);
    }

    #[Test]
    public function splitCsiSequenceReturnsRemainder(): void
    {
        $result = InputParser::parse("\x1b[");
        self::assertSame([], $result->messages);
        self::assertSame("\x1b[", $result->remainder);
    }

    #[Test]
    public function splitCsiWithPartialParamsReturnsRemainder(): void
    {
        $result = InputParser::parse("\x1b[1;5");
        self::assertSame([], $result->messages);
        self::assertSame("\x1b[1;5", $result->remainder);
    }

    #[Test]
    public function splitUtf8TwoByteReturnsRemainder(): void
    {
        $result = InputParser::parse("a\xc3");
        self::assertCount(1, $result->messages);
        self::assertSame('a', $result->messages[0]->key);
        self::assertSame("\xc3", $result->remainder);
    }

    #[Test]
    public function splitUtf8ThreeByteReturnsRemainder(): void
    {
        $result = InputParser::parse("a\xE4\xBD");
        self::assertCount(1, $result->messages);
        self::assertSame('a', $result->messages[0]->key);
        self::assertSame("\xE4\xBD", $result->remainder);
    }

    #[Test]
    public function splitUtf8FourByteReturnsRemainder(): void
    {
        $result = InputParser::parse("a\xf0\x9f\x98");
        self::assertCount(1, $result->messages);
        self::assertSame('a', $result->messages[0]->key);
        self::assertSame("\xf0\x9f\x98", $result->remainder);
    }

    #[Test]
    public function remainderCompletedInNextParseCsi(): void
    {
        $first = InputParser::parse("\x1b[");
        self::assertSame([], $first->messages);

        $second = InputParser::parse($first->remainder . 'A');
        self::assertCount(1, $second->messages);
        self::assertSame(Key::Up, $second->messages[0]->key);
        self::assertSame('', $second->remainder);
    }

    #[Test]
    public function remainderCompletedInNextParseUtf8(): void
    {
        $first = InputParser::parse("\xE4\xBD");
        self::assertSame([], $first->messages);

        $second = InputParser::parse($first->remainder . "\xA0");
        self::assertCount(1, $second->messages);
        self::assertSame("\xE4\xBD\xA0", $second->messages[0]->key);
        self::assertSame('', $second->remainder);
    }

    #[Test]
    public function remainderCompletedInNextParseEscape(): void
    {
        $first = InputParser::parse("\x1b");
        self::assertSame([], $first->messages);

        $second = InputParser::parse($first->remainder . '[B');
        self::assertCount(1, $second->messages);
        self::assertSame(Key::Down, $second->messages[0]->key);
        self::assertSame('', $second->remainder);
    }

    #[Test]
    public function splitSgrMouseReturnsRemainder(): void
    {
        $result = InputParser::parse("\x1b[<0;5;3");
        self::assertSame([], $result->messages);
        self::assertSame("\x1b[<0;5;3", $result->remainder);
    }

    #[Test]
    public function splitSgrMouseCompletedInNextParse(): void
    {
        $first = InputParser::parse("\x1b[<0;5;3");
        self::assertSame([], $first->messages);

        $second = InputParser::parse($first->remainder . 'M');
        self::assertCount(1, $second->messages);
        self::assertInstanceOf(MouseMsg::class, $second->messages[0]);
        self::assertSame(MouseAction::Press, $second->messages[0]->action);
        self::assertSame('', $second->remainder);
    }

    #[Test]
    public function bareEscapeAtEndIsRemainder(): void
    {
        $result = InputParser::parse("\x1b");
        self::assertSame([], $result->messages);
        self::assertSame("\x1b", $result->remainder);
    }

    #[Test]
    public function splitSsThreeReturnsRemainder(): void
    {
        $result = InputParser::parse("\x1bO");
        self::assertSame([], $result->messages);
        self::assertSame("\x1bO", $result->remainder);
    }

    #[Test]
    public function splitSsThreeCompletedInNextParse(): void
    {
        $first = InputParser::parse("\x1bO");
        self::assertSame([], $first->messages);

        $second = InputParser::parse($first->remainder . 'P');
        self::assertCount(1, $second->messages);
        self::assertSame(Key::F1, $second->messages[0]->key);
        self::assertSame('', $second->remainder);
    }

    #[Test]
    public function remainderExcludesParsedBytesBeforeIncompleteCsi(): void
    {
        $result = InputParser::parse("ab\x1b[1;5");
        self::assertCount(2, $result->messages);
        self::assertSame('a', $result->messages[0]->key);
        self::assertSame('b', $result->messages[1]->key);
        self::assertSame("\x1b[1;5", $result->remainder);
    }

    #[Test]
    public function remainderExcludesParsedBytesBeforeIncompleteSsThree(): void
    {
        $result = InputParser::parse("x\x1bO");
        self::assertCount(1, $result->messages);
        self::assertSame('x', $result->messages[0]->key);
        self::assertSame("\x1bO", $result->remainder);
    }

    #[Test]
    public function flushBareEscapeEmitsKeyEscape(): void
    {
        $result = InputParser::parse("\x1b", flush: true);
        self::assertCount(1, $result->messages);
        self::assertSame(Key::Escape, $result->messages[0]->key);
        self::assertSame('', $result->remainder);
    }

    #[Test]
    public function flushBareEscapeAfterParsedCharsEmitsKeyEscape(): void
    {
        $result = InputParser::parse("a\x1b", flush: true);
        self::assertCount(2, $result->messages);
        self::assertSame('a', $result->messages[0]->key);
        self::assertSame(Key::Escape, $result->messages[1]->key);
        self::assertSame('', $result->remainder);
    }

    #[Test]
    public function flushIncompleteCsiEmitsEscapeAndLiteralChars(): void
    {
        $result = InputParser::parse("\x1b[1;5", flush: true);
        self::assertCount(5, $result->messages);
        self::assertSame(Key::Escape, $result->messages[0]->key);
        self::assertSame('[', $result->messages[1]->key);
        self::assertSame('1', $result->messages[2]->key);
        self::assertSame(';', $result->messages[3]->key);
        self::assertSame('5', $result->messages[4]->key);
        self::assertSame('', $result->remainder);
    }

    #[Test]
    public function flushIncompleteSsThreeEmitsEscapeAndLiteral(): void
    {
        $result = InputParser::parse("\x1bO", flush: true);
        self::assertCount(2, $result->messages);
        self::assertSame(Key::Escape, $result->messages[0]->key);
        self::assertSame('O', $result->messages[1]->key);
        self::assertSame('', $result->remainder);
    }

    #[Test]
    public function flushTruncatedUtf8EmitsReplacementPerByte(): void
    {
        $result = InputParser::parse("\xc3", flush: true);
        self::assertCount(1, $result->messages);
        self::assertSame("\u{FFFD}", $result->messages[0]->key);
        self::assertSame('', $result->remainder);
    }

    #[Test]
    public function flushIncompleteSgrMouseEmitsLiteralChars(): void
    {
        $result = InputParser::parse("\x1b[<0;5;3", flush: true);
        self::assertCount(8, $result->messages);
        self::assertSame(Key::Escape, $result->messages[0]->key);
        self::assertSame('', $result->remainder);
    }

    #[Test]
    public function flushFalsePreservesRemainderBehavior(): void
    {
        $result = InputParser::parse("\x1b", flush: false);
        self::assertSame([], $result->messages);
        self::assertSame("\x1b", $result->remainder);
    }

    #[Test]
    public function flushCompleteInputHasNoEffect(): void
    {
        $normal = InputParser::parse('abc');
        $flushed = InputParser::parse('abc', flush: true);
        self::assertCount(3, $flushed->messages);
        self::assertSame('', $flushed->remainder);
        self::assertEquals($normal->messages, $flushed->messages);
    }

    #[Test]
    public function flushMultipleConsecutiveEscapesEmitsAll(): void
    {
        $result = InputParser::parse("\x1b\x1b", flush: true);
        self::assertCount(2, $result->messages);
        self::assertSame(Key::Escape, $result->messages[0]->key);
        self::assertSame(Key::Escape, $result->messages[1]->key);
    }

    #[Test]
    public function flushIncompleteCsiWithByte0x80EmitsReplacementChar(): void
    {
        $result = InputParser::parse("\x1b[\x80", flush: true);
        self::assertCount(3, $result->messages);
        self::assertSame(Key::Escape, $result->messages[0]->key);
        self::assertSame('[', $result->messages[1]->key);
        self::assertSame("\u{FFFD}", $result->messages[2]->key);
    }

    #[Test]
    public function modifyOtherKeysShiftEnterIsShiftEnter(): void
    {
        $result = InputParser::parseSequence("\x1b[27;2;13~");

        self::assertEquals(new KeyMsg(Key::Enter, shift: true), $result);
        self::assertNull(InputParser::parseSequence("\x1b[127;5;13~"), 'a parameter that only ends in 27 is no modifyOtherKeys report');
    }

    #[Test]
    public function altOAndAltBracketHeldUntilATimeoutArriveAsAltCombos(): void
    {
        self::assertEquals(new KeyMsg('O', alt: true), InputParser::parseSequence("\x1bO"));
        self::assertEquals(new KeyMsg('[', alt: true), InputParser::parseSequence("\x1b["));
    }

    /**
     * @return iterable<string, array{int, ?string}>
     */
    public static function codepointsAtThePrintableEdges(): iterable
    {
        yield 'tilde' => [0x7E, '~'];
        yield 'first C1 control' => [0x80, null];
        yield 'last C1 control' => [0x9F, null];
        yield 'no-break space' => [0xA0, "\u{A0}"];
        yield 'last before the surrogates' => [0xD7FF, "\u{D7FF}"];
        yield 'first surrogate' => [0xD800, null];
        yield 'last surrogate' => [0xDFFF, null];
        yield 'private use Kitty names no key' => [0xF000, null];
        yield 'last private use' => [0xF8FF, null];
        yield 'first after private use' => [0xF900, "\u{F900}"];
        yield 'last codepoint' => [0x10FFFF, "\u{10FFFF}"];
        yield 'past the last codepoint' => [0x110000, null];
    }

    #[Test]
    #[DataProvider('codepointsAtThePrintableEdges')]
    public function aKittyKeyIsTextOnlyWhenItsCodepointPrints(int $codepoint, ?string $text): void
    {
        self::assertSame($text, InputParser::parseSequence("\x1b[{$codepoint}u")?->key);
    }

    #[Test]
    public function kittyCsiUDeleteCodepoint(): void
    {
        $result = InputParser::parseSequence("\x1b[57426u");
        $this->assertInstanceOf(KeyMsg::class, $result);
        $this->assertSame(Key::Delete, $result->key);
    }

    /**
     * @return iterable<string, array{string, Key}>
     */
    public static function kittyFunctionalCodepointProvider(): iterable
    {
        yield 'Escape' => ["\x1b[27u", Key::Escape];
        yield 'Enter' => ["\x1b[13u", Key::Enter];
        yield 'Tab' => ["\x1b[9u", Key::Tab];
        yield 'Backspace' => ["\x1b[127u", Key::Backspace];
        yield 'keypad Enter' => ["\x1b[57414u", Key::Enter];
        yield 'keypad Left' => ["\x1b[57417u", Key::Left];
        yield 'keypad Right' => ["\x1b[57418u", Key::Right];
        yield 'keypad Up' => ["\x1b[57419u", Key::Up];
        yield 'keypad Down' => ["\x1b[57420u", Key::Down];
        yield 'keypad PageUp' => ["\x1b[57421u", Key::PageUp];
        yield 'keypad PageDown' => ["\x1b[57422u", Key::PageDown];
        yield 'keypad Home' => ["\x1b[57423u", Key::Home];
        yield 'keypad End' => ["\x1b[57424u", Key::End];
        yield 'keypad Insert' => ["\x1b[57425u", Key::Insert];
        yield 'keypad Delete' => ["\x1b[57426u", Key::Delete];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function kittyKeysWithNoNameHere(): iterable
    {
        yield 'Caps Lock' => ["\x1b[57358u"];
        yield 'Scroll Lock' => ["\x1b[57359u"];
        yield 'Num Lock' => ["\x1b[57360u"];
        yield 'Print Screen' => ["\x1b[57361u"];
        yield 'Pause' => ["\x1b[57362u"];
        yield 'Menu' => ["\x1b[57363u"];
        yield 'F13' => ["\x1b[57376u"];
        yield 'F24' => ["\x1b[57387u"];
        yield 'F35' => ["\x1b[57398u"];
        yield 'keypad separator' => ["\x1b[57416u"];
        yield 'media play' => ["\x1b[57428u"];
        yield 'left Shift' => ["\x1b[57441u"];
        yield 'a code the protocol never sends' => ["\x1b[57344u"];
    }

    #[DataProvider('kittyKeysWithNoNameHere')]
    #[Test]
    public function aKittyKeyWithNoNameHereIsIgnoredRatherThanTakenForAnother(string $sequence): void
    {
        self::assertNull(InputParser::parseSequence($sequence));
    }

    #[DataProvider('kittyFunctionalCodepointProvider')]
    #[Test]
    public function kittyFunctionalCodepoints(string $sequence, Key $expectedKey): void
    {
        $result = InputParser::parseSequence($sequence);
        $this->assertInstanceOf(KeyMsg::class, $result);
        $this->assertSame($expectedKey, $result->key);
    }

    #[Test]
    public function kittyCsiUUnknownCodepointReturnsNull(): void
    {
        $result = InputParser::parseSequence("\x1b[57999u");
        $this->assertNull($result);
    }

    #[Test]
    public function aModifiedLetterBeyondAsciiArrivesUnderTheKittyProtocol(): void
    {
        $result = InputParser::parseSequence("\x1b[228;3u");

        $this->assertInstanceOf(KeyMsg::class, $result);
        $this->assertSame('ä', $result->key);
        $this->assertTrue($result->alt);
    }

    #[Test]
    public function kittyCsiUWithModifiersAndEventType(): void
    {
        $result = InputParser::parseSequence("\x1b[57426;5:3u");
        $this->assertInstanceOf(KeyMsg::class, $result);
        $this->assertSame(Key::Delete, $result->key);
        $this->assertTrue($result->ctrl);
        $this->assertSame(KeyEventType::Release, $result->eventType);
    }

    #[Test]
    public function anArrowHeldWithSuperIsNotTakenForAPlainArrow(): void
    {
        self::assertNull(InputParser::parseSequence("\x1b[1;9A"));
    }

    #[Test]
    public function csiArrowWithKittyEventTypeRelease(): void
    {
        $result = InputParser::parseSequence("\x1b[1;5:3A");

        $this->assertInstanceOf(KeyMsg::class, $result);
        $this->assertSame(Key::Up, $result->key);
        $this->assertTrue($result->ctrl);
        $this->assertSame(KeyEventType::Release, $result->eventType);
    }

    /**
     * @return iterable<string, array{string, Key|string}>
     */
    public static function kittyLegacyCodepointProvider(): iterable
    {
        yield 'Tab via codepoint 9' => ["\x1b[9u", Key::Tab];
        yield 'Enter via codepoint 13' => ["\x1b[13u", Key::Enter];
        yield 'Escape via codepoint 27' => ["\x1b[27u", Key::Escape];
        yield 'Backspace via codepoint 127' => ["\x1b[127u", Key::Backspace];
        yield 'Space via codepoint 32' => ["\x1b[32u", ' '];
    }

    #[DataProvider('kittyLegacyCodepointProvider')]
    #[Test]
    public function kittyLegacyCodepoints(string $sequence, Key|string $expectedKey): void
    {
        $result = InputParser::parseSequence($sequence);
        $this->assertInstanceOf(KeyMsg::class, $result);
        $this->assertSame($expectedKey, $result->key);
    }

    /**
     * A terminal answers questions on the same stream the reader types on. A reply we
     * do not understand was read as text instead, so it arrived as a keystroke nobody
     * pressed — a device-attributes answer as a cursor move.
     */
    #[Test]
    public function aTerminalReportIsNotAKeystroke(): void
    {
        self::assertNull(InputParser::parseSequence("\x1b[?62;1;4c"), 'device attributes');
        self::assertNull(InputParser::parseSequence("\x1b[>1;95;0c"), 'secondary device attributes');
        self::assertNull(InputParser::parseSequence("\x1b[0n"), 'status report');
        self::assertNull(InputParser::parseSequence("\x1b[24;80R"), 'cursor position report');
        self::assertNull(InputParser::parseSequence("\x1b[4;24;80t"), 'window report');

        // Its three coordinate bytes never arrived. Reading what did as text would
        // put an Escape nobody pressed in front of the reader's next keystroke.
        self::assertNull(InputParser::parseSequence("\x1b[M"), 'a mouse report cut short');
    }
}
