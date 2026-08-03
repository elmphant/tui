<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Search\Matcher;
use Elmphant\Tui\Testing\ScreenBuffer;
use Elmphant\Tui\Widget\Dropdown;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DropdownTest extends TestCase
{
    #[Test]
    public function closedItShowsTheSelectedValueOnOneRow(): void
    {
        $dropdown = Dropdown::new(['json', 'yaml'])->withSelected('yaml');

        self::assertSame(1, $dropdown->constraint()->min);
        self::assertStringContainsString('yaml', self::screen($dropdown)->getLine(0));
    }

    #[Test]
    public function spaceOpensAFloatingListBelowTheRow(): void
    {
        $opened = self::opened(Dropdown::new(['json', 'yaml']));

        self::assertTrue($opened->isOpen());
        self::assertStringContainsString('yaml', self::screen($opened)->getPlainText(), 'The popup drew');
    }

    #[Test]
    public function enterPicksTheOptionUnderTheCursorAndCloses(): void
    {
        $area = new Rect(0, 0, 20, 1);
        $opened = self::opened(Dropdown::new(['json', 'yaml']));

        $moved = $opened->update(new KeyMsg(Key::Down), $area)->model;
        $picked = $moved->update(new KeyMsg(Key::Enter), $area)->model;

        self::assertSame('yaml', $picked->selectedValue());
        self::assertFalse($picked->isOpen());
    }

    #[Test]
    public function escapeClosesWithoutChangingTheValue(): void
    {
        $area = new Rect(0, 0, 20, 1);
        $opened = self::opened(Dropdown::new(['json', 'yaml'])->withSelected('json'));

        $cancelled = $opened->update(new KeyMsg(Key::Down), $area)->model
            ->update(new KeyMsg(Key::Escape), $area)->model;

        self::assertSame('json', $cancelled->selectedValue());
        self::assertFalse($cancelled->isOpen());
    }

    #[Test]
    public function typingNarrowsTheListAndTheCursorFollowsIt(): void
    {
        $area = new Rect(0, 0, 20, 1);

        $typed = self::opened(Dropdown::new(['json', 'yaml', 'yml']))
            ->update(new KeyMsg('y'), $area)->model
            ->update(new KeyMsg('m'), $area)->model;

        $picked = $typed->update(new KeyMsg(Key::Enter), $area)->model;

        self::assertSame('yml', $picked->selectedValue(), 'Only yml matches "ym"');
    }

    #[Test]
    public function theFirstOptionOfferedIsTheMatcherSBestMatch(): void
    {
        $area = new Rect(0, 0, 20, 1);

        $ranked = new class implements Matcher {
            public function matching(string $query, array $haystack): array
            {
                return [2, 0];
            }
        };

        $picked = Dropdown::new(['first', 'second', 'third'])
            ->withMatcher($ranked)
            ->withFocus(true)
            ->update(new KeyMsg(' '), $area)->model
            ->update(new KeyMsg('x'), $area)->model
            ->update(new KeyMsg(Key::Enter), $area)->model;

        self::assertSame('third', $picked->selectedValue(), 'The matcher ranked it first');
    }

    #[Test]
    public function theQueryIsMatchedAgainstTheLabelTheUserCanSee(): void
    {
        $area = new Rect(0, 0, 20, 1);

        $picked = Dropdown::new(['a1b2', 'c3d4'])
            ->withLabels(['a1b2' => 'Production', 'c3d4' => 'Staging'])
            ->withFocus(true)
            ->update(new KeyMsg(' '), $area)->model
            ->update(new KeyMsg('S'), $area)->model
            ->update(new KeyMsg('t'), $area)->model
            ->update(new KeyMsg(Key::Enter), $area)->model;

        self::assertSame('c3d4', $picked->selectedValue(), 'Typing the label picks its opaque value');
    }

    #[Test]
    public function withNothingSelectedItShowsThePlaceholderDim(): void
    {
        $theme = Theme::dark();

        self::assertNotSame(
            $theme->base->foregroundValue(),
            $theme->dim->foregroundValue(),
            'The theme must distinguish the two, or this test proves nothing',
        );

        $frame = self::frameFor(Dropdown::new(['json'])->withPlaceholder('choose a format'), $theme);
        $selected = self::frameFor(Dropdown::new(['json'])->withSelected('json'), $theme);

        self::assertSame($theme->dim->foregroundValue(), self::foregroundOfFirstCell($frame));
        self::assertSame($theme->base->foregroundValue(), self::foregroundOfFirstCell($selected));
    }

    #[Test]
    public function backspaceWidensTheListAgain(): void
    {
        $area = new Rect(0, 0, 20, 1);

        $picked = self::opened(Dropdown::new(['json', 'yaml']))
            ->update(new KeyMsg('y'), $area)->model
            ->update(new KeyMsg(Key::Backspace), $area)->model
            ->update(new KeyMsg(Key::Enter), $area)->model;

        self::assertSame('json', $picked->selectedValue(), 'The whole list is back, cursor at the top');
    }

    #[Test]
    public function theCursorStopsAtBothEndsOfTheList(): void
    {
        $area = new Rect(0, 0, 20, 1);
        $opened = self::opened(Dropdown::new(['json', 'yaml']));

        $atTop = $opened->update(new KeyMsg(Key::Up), $area)->model;
        $atBottom = $opened
            ->update(new KeyMsg(Key::Down), $area)->model
            ->update(new KeyMsg(Key::Down), $area)->model;

        self::assertSame('json', $atTop->update(new KeyMsg(Key::Enter), $area)->model->selectedValue());
        self::assertSame('yaml', $atBottom->update(new KeyMsg(Key::Enter), $area)->model->selectedValue());
    }

    #[Test]
    public function freeTextCommitsAQueryThatMatchesNoOption(): void
    {
        $area = new Rect(0, 0, 20, 1);

        $dropdown = self::opened(Dropdown::new(['json', 'yaml'])->withFreeText(true));
        $typed = $dropdown->update(new KeyMsg('x'), $area)->model;

        self::assertSame('x', $typed->update(new KeyMsg(Key::Enter), $area)->model->selectedValue());
    }

    #[Test]
    public function aClosedSetIgnoresEnterOnAQueryThatMatchesNothing(): void
    {
        $area = new Rect(0, 0, 20, 1);

        $typed = self::opened(Dropdown::new(['json', 'yaml']))->update(new KeyMsg('x'), $area)->model;

        self::assertNull($typed->update(new KeyMsg(Key::Enter), $area)->model->selectedValue());
    }

    #[Test]
    public function walkingALongListKeepsTheCursorInsideTheVisibleWindow(): void
    {
        $area = new Rect(0, 0, 40, 1);
        $options = array_map(static fn (int $index): string => 'option' . $index, range(1, 36));

        $dropdown = Dropdown::new($options)
            ->withViewport(Rect::fromSize(60, 14))
            ->withFocus(true)
            ->update(new KeyMsg(' '), $area)->model;

        for ($step = 0; $step < 20; ++$step) {
            $dropdown = $dropdown->update(new KeyMsg(Key::Down), $area)->model;
        }

        $frame = new Frame(60, 14);
        $context = new RenderContext(Theme::dark());
        $context->renderRoot($dropdown, $frame, $area);

        self::assertStringContainsString(
            'option21',
            ScreenBuffer::fromFrame($frame)->getPlainText(),
            'The highlighted option is still drawn after walking past the window',
        );
    }

    #[Test]
    public function everyActionCanBeRebound(): void
    {
        $area = new Rect(0, 0, 20, 1);

        $rebound = Dropdown::new(['json', 'yaml'])
            ->withFocus(true)
            ->withKeybindings(new Keybindings([
                Actions::TOGGLE => ['ctrl+o'],
                Actions::SELECT => ['tab'],
                Actions::CANCEL => ['ctrl+c'],
                Actions::FOCUS_NEXT => ['ctrl+n'],
            ]));

        $ignored = $rebound->update(new KeyMsg(' '), $area)->model;
        self::assertFalse($ignored->isOpen(), 'Space no longer opens it');

        $opened = $rebound->update(new KeyMsg('o', ctrl: true), $area)->model;
        self::assertTrue($opened->isOpen(), 'but the rebound key does');

        $picked = $opened
            ->update(new KeyMsg('n', ctrl: true), $area)->model
            ->update(new KeyMsg(Key::Tab), $area)->model;

        self::assertSame('yaml', $picked->selectedValue(), 'Rebound move and select');

        $cancelled = $opened->update(new KeyMsg('c', ctrl: true), $area)->model;
        self::assertFalse($cancelled->isOpen(), 'and rebound cancel');
    }

    #[Test]
    public function anOpenDropdownQueuesOnePopupPerFrameAndNeverRedrawsItsRowBelow(): void
    {
        $opened = self::opened(Dropdown::new(['json', 'yaml'])->withSelected('json'));

        $frame = new Frame(30, 6);
        $context = new RenderContext(Theme::dark());
        $context->renderRoot($opened, $frame, new Rect(0, 0, 20, 1));

        self::assertSame([], $context->floats(), 'The queue is drained, not left behind');
        self::assertStringNotContainsString(
            '▾ json',
            ScreenBuffer::fromFrame($frame)->getLine(2),
            'The closed row is drawn once, at the anchor only',
        );
    }

    private static function frameFor(Dropdown $dropdown, Theme $theme): Frame
    {
        $frame = new Frame(30, 6);
        (new RenderContext($theme))->renderRoot($dropdown, $frame, new Rect(0, 0, 20, 1));

        return $frame;
    }

    /**
     * The marker occupies cell 0 of row 0, and carries the row's style.
     */
    private static function foregroundOfFirstCell(Frame $frame): int
    {
        return $frame->foreground(0);
    }

    private static function screen(Dropdown $dropdown): ScreenBuffer
    {
        $frame = new Frame(30, 8);
        $context = new RenderContext(Theme::dark());
        $context->renderRoot($dropdown, $frame, new Rect(0, 0, 20, 1));

        return ScreenBuffer::fromFrame($frame);
    }

    private static function opened(Dropdown $dropdown): Dropdown
    {
        return $dropdown->withFocus(true)->update(new KeyMsg(' '), new Rect(0, 0, 20, 1))->model;
    }
}
