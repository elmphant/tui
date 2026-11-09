<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use Elmphant\Tui\Core\FocusState;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseButton;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Message\PasteMsg;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Runtime\DescribesKeys;
use Elmphant\Tui\Runtime\KeyHelpOverlay;
use Elmphant\Tui\Testing\ScreenBuffer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

final class KeyHelpOverlayTest extends TestCase
{
    #[Test]
    public function theHelpIsABoxInTheMiddleOfTheScreenWithAColumnOfKeysAndAGapBetweenGroups(): void
    {
        $screen = $this->drawn(['Here' => ['↑' => 'up', 'Enter' => 'open'], 'Around' => [], 'App' => ['q' => 'quit']], 40, 12);

        self::assertSame(
            [
                str_repeat('.', 40),
                str_repeat('.', 40),
                $screen->lineAt(2),
                self::row('Here'),
                self::row('↑      up'),
                self::row('Enter  open'),
                self::row(''),
                self::row('App'),
                self::row('q      quit'),
                '........╰' . str_repeat('─', 21) . '╯.........',
                str_repeat('.', 40),
                str_repeat('.', 40),
            ],
            array_map($screen->lineAt(...), range(0, 11)),
        );
        self::assertStringStartsWith('........╭', $screen->lineAt(2));
        self::assertStringContainsString('Keys · Esc to close', $screen->lineAt(2));
    }

    #[Test]
    public function aScreenTooShortForTheHelpGrowsDownwardsByJustTheRowsItNeeds(): void
    {
        $frame = new Frame(40, 30);

        $this->opened()->drawOver($frame, new RenderContext(Theme::dark()), self::model(['Here' => ['q' => 'quit']]), FocusState::nowhere(), Rect::fromSize(40, 2));

        $screen = ScreenBuffer::fromFrame($frame);
        self::assertStringContainsString('Keys · Esc to close', $screen->lineAt(1));
        self::assertStringContainsString('q  quit', $screen->lineAt(3));
        self::assertStringContainsString('╰', $screen->lineAt(4));
        self::assertSame(5, $frame->drawnHeight());
    }

    #[Test]
    public function aLineWiderThanTheTitleWidensTheBox(): void
    {
        $screen = $this->drawn(['Here' => ['Enter' => 'open the selected row']], 40, 12);

        self::assertSame('....│ Enter  open the selected row │....', $screen->lineAt(6));
    }

    #[Test]
    public function aClosedHelpDrawsNothing(): void
    {
        $frame = new Frame(10, 4);
        (new KeyHelpOverlay())->drawOver($frame, new RenderContext(Theme::dark()), self::model(['Here' => ['q' => 'quit']]), FocusState::nowhere(), $frame->area());

        self::assertSame('', trim(ScreenBuffer::fromFrame($frame)->plainText()));
    }

    #[Test]
    public function aClosedHelpTakesTheKeyThatOpensItAndLetsEveryOtherMessageThrough(): void
    {
        $overlay = new KeyHelpOverlay();

        self::assertFalse($overlay->takes(new KeyMsg('x'), self::model([]), FocusState::nowhere()));
        self::assertFalse($overlay->takes(self::mouse(MouseAction::Press), self::model([]), FocusState::nowhere()));
        self::assertFalse($overlay->takes(new PasteMsg('pasted'), self::model([]), FocusState::nowhere()));
        self::assertFalse($overlay->takes(new KeyMsg('?'), new stdClass(), FocusState::nowhere()), 'a model with no keys to describe has no help');
        self::assertFalse($overlay->isOpen());
        self::assertTrue($overlay->takes(new KeyMsg('?'), self::model([]), FocusState::nowhere()));
        self::assertTrue($overlay->isOpen());
    }

    #[Test]
    public function anOpenHelpTakesAPasteSoNothingBehindItHearsIt(): void
    {
        $overlay = new KeyHelpOverlay();
        $overlay->takes(new KeyMsg('?'), self::model([]), FocusState::nowhere());

        self::assertTrue($overlay->takes(new PasteMsg('pasted'), self::model([]), FocusState::nowhere()));
        self::assertTrue($overlay->isOpen());
    }

    #[Test]
    public function aLineWiderThanTheScreenIsCutInsideTheBox(): void
    {
        $screen = $this->drawn(['Here' => ['Enter' => 'open the selected row']], 22, 6);

        self::assertSame('.│ Enter  open the  │.', $screen->lineAt(3));
    }

    #[Test]
    public function aKeyNamedByANumberIsListedLikeAnyOther(): void
    {
        $screen = $this->drawn(['Here' => ['7' => 'seven', 'Esc' => 'back']], 40, 12);

        self::assertStringContainsString('│ 7    seven', $screen->lineAt(5));
    }

    #[Test]
    public function theSmallestScreenStillShowsTheBox(): void
    {
        self::assertSame('.╭─╮.', $this->drawn(['Here' => ['q' => 'quit']], 5, 5)->lineAt(1));
    }

    #[Test]
    public function aScreenTooNarrowForTheBoxShowsNothing(): void
    {
        $screen = $this->drawn(['Here' => ['q' => 'quit']], 4, 10);

        self::assertSame(str_repeat("....\n", 9) . '....', $screen->plainText());
    }

    #[Test]
    public function eachScrollKeyMovesTheListByItsOwnAmount(): void
    {
        self::assertSame('Here', $this->firstLineAfter([]));
        self::assertSame('01  key 1', $this->firstLineAfter([new KeyMsg('j')]));
        self::assertSame('01  key 1', $this->firstLineAfter([new KeyMsg(Key::Down)]));
        self::assertSame('01  key 1', $this->firstLineAfter([new KeyMsg('j'), new KeyMsg('j'), new KeyMsg('k')]));
        self::assertSame('01  key 1', $this->firstLineAfter([new KeyMsg('j'), new KeyMsg('j'), new KeyMsg(Key::Up)]));
        self::assertSame('10  key 10', $this->firstLineAfter([new KeyMsg(' ')]));
        self::assertSame('10  key 10', $this->firstLineAfter([new KeyMsg(Key::PageDown)]));
        self::assertSame('10  key 10', $this->firstLineAfter([new KeyMsg(Key::PageDown), new KeyMsg(Key::PageDown), new KeyMsg(Key::PageUp)]));
        self::assertSame('25  key 25', $this->firstLineAfter([new KeyMsg(Key::End)]));
        self::assertSame('24  key 24', $this->firstLineAfter([new KeyMsg(Key::End), new KeyMsg('k')]));
        self::assertSame('Here', $this->firstLineAfter([new KeyMsg(Key::End), new KeyMsg(Key::Home)]));
        self::assertSame('Here', $this->firstLineAfter([new KeyMsg('k')]), 'nothing is above the top');
        self::assertSame('02  key 2', $this->firstLineAfter([new KeyMsg('j'), new KeyMsg('j'), new KeyMsg('x')]), 'a key with no meaning here moves nothing');
    }

    #[Test]
    public function theWheelScrollsAndAClickMovesNothing(): void
    {
        self::assertSame('01  key 1', $this->firstLineAfter([self::mouse(MouseAction::WheelDown)]));
        self::assertSame('01  key 1', $this->firstLineAfter([self::mouse(MouseAction::WheelDown), self::mouse(MouseAction::WheelDown), self::mouse(MouseAction::WheelUp)]));
        self::assertSame('01  key 1', $this->firstLineAfter([self::mouse(MouseAction::WheelDown), self::mouse(MouseAction::Press)]));
    }

    #[Test]
    public function reopenedTheHelpStartsAtTheTop(): void
    {
        self::assertSame('Here', $this->firstLineAfter([new KeyMsg(Key::End), new KeyMsg(Key::Escape), new KeyMsg('?')]));
    }

    #[Test]
    public function whileOpenItTakesEveryKeyAndPressSoNothingBehindReacts(): void
    {
        foreach ([new KeyMsg('x'), new KeyMsg('j'), self::mouse(MouseAction::Press)] as $message) {
            $overlay = $this->opened();

            self::assertTrue($overlay->takes($message, self::model([]), FocusState::nowhere()));
            self::assertTrue($overlay->isOpen());
        }
    }

    #[Test]
    public function escapeTheQuestionMarkAndTheKeyThatOpenedItCloseItAndGoNoFurther(): void
    {
        foreach ([new KeyMsg(Key::Escape), new KeyMsg('?'), new KeyMsg('k', ctrl: true)] as $message) {
            $overlay = $this->opened();

            self::assertTrue($overlay->takes($message, self::model([]), FocusState::nowhere()));
            self::assertFalse($overlay->isOpen());
        }
    }

    #[Test]
    public function anotherShortcutClosesItAndStillActs(): void
    {
        $overlay = $this->opened();

        self::assertFalse($overlay->takes(new KeyMsg('q', ctrl: true), self::model([]), FocusState::nowhere()));
        self::assertFalse($overlay->isOpen());
    }

    /**
     * @param list<KeyMsg|MouseMsg> $messages
     */
    private function firstLineAfter(array $messages): string
    {
        $keys = [];
        foreach (range(1, 30) as $number) {
            $keys[\sprintf('%02d', $number)] = "key $number";
        }
        $model = self::model(['Here' => $keys]);
        $overlay = $this->opened();
        $frame = new Frame(40, 10);
        $context = new RenderContext(Theme::dark());

        $overlay->drawOver($frame, $context, $model, FocusState::nowhere(), $frame->area());
        foreach ($messages as $message) {
            $overlay->takes($message, $model, FocusState::nowhere());
            $overlay->drawOver($frame, $context, $model, FocusState::nowhere(), $frame->area());
        }

        return trim(str_replace('│', '', ScreenBuffer::fromFrame($frame)->lineAt(2)));
    }

    /**
     * @param array<string, array<array-key, string>> $groups
     */
    private function drawn(array $groups, int $width, int $height): ScreenBuffer
    {
        $frame = new Frame($width, $height);
        for ($row = 0; $row < $height; ++$row) {
            $frame->putString(0, $row, str_repeat('.', $width));
        }

        $this->opened()->drawOver($frame, new RenderContext(Theme::dark()), self::model($groups), FocusState::nowhere(), $frame->area());

        return ScreenBuffer::fromFrame($frame);
    }

    private function opened(): KeyHelpOverlay
    {
        $overlay = new KeyHelpOverlay();
        $overlay->takes(new KeyMsg('?'), self::model([]), FocusState::nowhere());

        return $overlay;
    }

    private static function row(string $text): string
    {
        return '........│ ' . $text . str_repeat(' ', 19 - mb_strwidth($text)) . ' │.........';
    }

    private static function mouse(MouseAction $action): MouseMsg
    {
        return new MouseMsg(20, 5, $action, MouseButton::Left);
    }

    /**
     * @param array<string, array<array-key, string>> $groups
     */
    private static function model(array $groups): DescribesKeys
    {
        return new class($groups) implements DescribesKeys {
            /**
             * @param array<string, array<array-key, string>> $groups
             */
            public function __construct(private readonly array $groups)
            {
            }

            public function opensKeyHelp(KeyMsg $key, FocusState $focus): bool
            {
                return $key->key === '?' || ($key->ctrl && $key->key === 'k');
            }

            public function keyHelp(FocusState $focus): array
            {
                return $this->groups;
            }
        };
    }
}
