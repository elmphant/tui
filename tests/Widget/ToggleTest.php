<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Input\Presets\VimMode;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\Submitted;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Style;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Testing\WidgetHarness;
use Elmphant\Tui\Widget\Choice\Toggle;
use Elmphant\Tui\Widget\ValueItCannotHold;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ToggleTest extends TestCase
{
    #[Test]
    public function aNewToggleStartsOnNo(): void
    {
        $toggle = WidgetHarness::for(Toggle::new(), 20, 1);

        self::assertSame('○ Yes / ● No', trim($toggle->screen()->lineAt(0)));
        self::assertFalse($toggle->widget()->isOn());
    }

    #[Test]
    public function aToggleSetOnPicksYes(): void
    {
        $toggle = WidgetHarness::for(Toggle::new()->withOn(true), 20, 1);

        self::assertSame('● Yes / ○ No', trim($toggle->screen()->lineAt(0)));
        self::assertTrue($toggle->widget()->isOn());
    }

    #[Test]
    public function theArrowsPickASideAndStopAtTheEnd(): void
    {
        $toggle = WidgetHarness::for(Toggle::new(), 20, 1);

        self::assertTrue($toggle->press('left')->widget()->isOn());
        self::assertTrue($toggle->press('left')->widget()->isOn(), 'left on the left side stays there');
        self::assertFalse($toggle->press('right')->widget()->isOn());
        self::assertFalse($toggle->press('right')->widget()->isOn(), 'right on the right side stays there');
    }

    #[Test]
    public function spaceFlipsTheAnswer(): void
    {
        $toggle = WidgetHarness::for(Toggle::new(), 20, 1);

        self::assertTrue($toggle->press('space')->widget()->isOn());
        self::assertFalse($toggle->press('space')->widget()->isOn());
    }

    #[Test]
    public function aClickPicksTheSideUnderIt(): void
    {
        $toggle = WidgetHarness::for(Toggle::new(), 20, 1);

        self::assertTrue($toggle->click('Yes')->widget()->isOn());
        self::assertTrue($toggle->click('Yes')->widget()->isOn(), 'a click on the picked side keeps it');
        self::assertFalse($toggle->click('No')->widget()->isOn());
    }

    #[Test]
    public function theFirstLetterOfALabelPicksItsSide(): void
    {
        $toggle = WidgetHarness::for(Toggle::new(), 20, 1);

        self::assertTrue($toggle->press('y')->widget()->isOn());
        self::assertFalse($toggle->press('n')->widget()->isOn());
    }

    #[Test]
    public function labelsThatStartAlikeBindNoLetter(): void
    {
        $toggle = WidgetHarness::for(Toggle::new('Public', 'Private'), 20, 1);

        self::assertSame('○ Public / ● Private', trim($toggle->screen()->lineAt(0)));
        self::assertFalse($toggle->press('p')->widget()->isOn());
    }

    #[Test]
    public function aLabelThatDoesNotStartWithAnAsciiLetterHasNoLetterKey(): void
    {
        $toggle = WidgetHarness::for(Toggle::new('Éclair', 'No')->withOn(true), 20, 1);

        self::assertFalse($toggle->press('n')->widget()->isOn(), 'the other side keeps its letter');
        self::assertSame([Toggle::PICK_OFF], $toggle->widget()->momentaryKeybindings()->actions());
    }

    #[Test]
    public function labelsInAnotherScriptStillWorkWithTheArrows(): void
    {
        $toggle = WidgetHarness::for(Toggle::new('日本', '中国'), 20, 1);

        self::assertTrue($toggle->press('left')->widget()->isOn());
        self::assertSame([], $toggle->widget()->momentaryKeybindings()->actions());
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function labelsItRefuses(): iterable
    {
        yield 'the same twice' => ['Yes', 'Yes', 'Toggle labels must differ, "Yes" is on both sides.'];
        yield 'an empty one' => ['', 'No', 'A Toggle label must not be blank.'];
        yield 'only spaces' => ['Yes', '  ', 'A Toggle label must not be blank.'];
    }

    #[Test]
    #[DataProvider('labelsItRefuses')]
    public function labelsThatCannotBeToldApartAreRefused(string $on, string $off, string $problem): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($problem);

        Toggle::new($on, $off);
    }

    #[Test]
    public function newLabelsReplaceTheOldAndKeepTheAnswer(): void
    {
        $toggle = Toggle::new()->withOn(true)->withLabels('An', 'Aus');

        self::assertSame(['An', 'Aus'], $toggle->labels());
        self::assertTrue($toggle->isOn());
    }

    #[Test]
    public function newLabelsThatCannotBeToldApartAreRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Toggle labels must differ, "Ja" is on both sides.');

        Toggle::new()->withLabels('Ja', 'Ja');
    }

    #[Test]
    public function itNamesEachLetterByItsLabel(): void
    {
        self::assertSame(
            ['←/→' => 'choose', 'y' => 'Yes', 'n' => 'No', 'Space' => 'flip'],
            Toggle::new()->hints(),
        );
    }

    #[Test]
    public function aSubmittingToggleNamesEnter(): void
    {
        self::assertSame(
            ['←/→' => 'choose', 'y' => 'Yes', 'n' => 'No', 'Space' => 'flip', 'Enter' => 'submit'],
            Toggle::new()->withSubmit()->hints(),
        );
    }

    #[Test]
    public function itNamesOnlyTheKeysItBinds(): void
    {
        self::assertSame(['←/→' => 'choose', 'Space' => 'flip'], Toggle::new('Public', 'Private')->hints());
    }

    #[Test]
    public function itHoldsTheAnswerAsItsValue(): void
    {
        self::assertTrue(Toggle::new()->withValue(true)->value());
        self::assertFalse(Toggle::new()->withOn(true)->withValue(null)->value(), 'nothing given is No');
    }

    #[Test]
    public function aValueThatIsNotAnAnswerIsRefused(): void
    {
        $this->expectException(ValueItCannotHold::class);

        Toggle::new()->withValue('yes');
    }

    #[Test]
    public function aSubmittingToggleAnswersEnterWithThePickedLabel(): void
    {
        $area = new Rect(0, 0, 20, 1);
        $enter = new KeyMsg(Key::Enter);

        $outcome = Toggle::new()->withSubmit()->outcomeOf($enter, $area);

        self::assertEquals(new Submitted('No', $enter), $outcome);
    }

    #[Test]
    public function aToggleLeavesEnterAloneUntilToldToSubmit(): void
    {
        self::assertNull(Toggle::new()->outcomeOf(new KeyMsg(Key::Enter), new Rect(0, 0, 20, 1)));
    }

    #[Test]
    public function aSubmittingToggleSendsItsMessageOnEnter(): void
    {
        $sent = new Submitted('sent', new KeyMsg(Key::Enter));

        $result = Toggle::new()->withSubmit($sent)->update(new KeyMsg(Key::Enter), new Rect(0, 0, 20, 1));

        self::assertEquals(Cmd::message($sent), $result->command);
    }

    #[Test]
    public function vimsLeftAndRightPickASide(): void
    {
        $area = new Rect(0, 0, 20, 1);
        $toggle = Toggle::new()->withGlobalKeybindings(VimMode::keybindings());

        $left = $toggle->update(new KeyMsg('h'), $area)->model;
        self::assertInstanceOf(Toggle::class, $left);
        self::assertTrue($left->isOn());

        $right = $left->update(new KeyMsg('l'), $area)->model;
        self::assertInstanceOf(Toggle::class, $right);
        self::assertFalse($right->isOn());
    }

    #[Test]
    public function aLetterAGlobalKeyAlreadyBindsIsNotTheToggles(): void
    {
        $toggle = Toggle::new('Ja', 'Nein')->withGlobalKeybindings(VimMode::keybindings());

        self::assertSame([Toggle::PICK_OFF], $toggle->momentaryKeybindings()->actions(), 'j is the cursor down in vim mode');
    }

    #[Test]
    public function aLetterThatOpensAGlobalChordIsNotTheToggles(): void
    {
        $toggle = Toggle::new('Gut', 'Schlecht')->withGlobalKeybindings(VimMode::keybindings());

        self::assertSame([Toggle::PICK_OFF], $toggle->momentaryKeybindings()->actions(), 'g opens the g g chord');
    }

    #[Test]
    public function theKeyboardFillsThePickedSideAndTheRestReadsAsBody(): void
    {
        $theme = Theme::dark();
        $frame = new Frame(20, 1);

        Toggle::new()->render($frame, new Rect(0, 0, 20, 1), RenderContext::focused($theme));

        self::assertSameStyle($theme->cursorStyle, $frame, 8, 'the "● No" that is picked');
        self::assertSameStyle($theme->cursorStyle, $frame, 11);
        self::assertSameStyle($theme->base, $frame, 0, 'the "○ Yes" that is not');
        self::assertSameStyle($theme->base, $frame, 6, 'the separator');
    }

    #[Test]
    public function withoutTheKeyboardThePickedSideIsTheAccent(): void
    {
        $theme = Theme::dark();
        $frame = new Frame(20, 1);

        Toggle::new()->render($frame, new Rect(0, 0, 20, 1), new RenderContext($theme));

        self::assertSameStyle($theme->accent, $frame, 8);
        self::assertSameStyle($theme->dim, $frame, 0);
        self::assertSameStyle($theme->dim, $frame, 6);
    }

    private static function assertSameStyle(Style $expected, Frame $frame, int $column, string $message = ''): void
    {
        self::assertSame(
            [$expected->foregroundValue(), $expected->backgroundValue()],
            [$frame->foregrounds[$column], $frame->backgrounds[$column]],
            $message,
        );
    }
}
