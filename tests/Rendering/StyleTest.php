<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Rendering;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\AnsiColor;
use Elmphant\Tui\Rendering\Attr;
use Elmphant\Tui\Rendering\Color;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Style;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StyleTest extends TestCase
{
    #[Test]
    public function anInkWithNoColourOfItsOwnTakesThePanelsInk(): void
    {
        $panel = new Style(foreground: Color::hex('#282A36'), background: Color::hex('#BD93F9'));

        $bold = (new Style(attributes: Attr::BOLD))->readableOn($panel);

        self::assertEquals(Color::hex('#282A36'), $bold->foreground);
        self::assertSame(Attr::BOLD, $bold->attributes);
    }

    #[Test]
    public function anInkOnAReversedPanelWithoutBackgroundIsReversedButKeepsItsOwnAttributes(): void
    {
        $panel = new Style(attributes: Attr::REVERSE | Attr::BOLD);

        $ink = (new Style(foreground: Color::ansi(AnsiColor::Green), attributes: Attr::ITALIC))->readableOn($panel);

        self::assertEquals(Color::ansi(AnsiColor::Green), $ink->foreground);
        self::assertNull($ink->background);
        self::assertSame(Attr::ITALIC | Attr::REVERSE, $ink->attributes);
    }

    #[Test]
    public function defaultsToUnset(): void
    {
        $style = new Style();

        self::assertNull($style->foreground);
        self::assertNull($style->background);
        self::assertSame(0, $style->attributes);
    }

    #[Test]
    public function foregroundReturnsNewInstance(): void
    {
        $original = new Style();
        $modified = $original->withForeground(Color::ansi(AnsiColor::Red));

        self::assertNull($original->foreground);
        self::assertNotNull($modified->foreground);
        self::assertNotSame($original, $modified);
    }

    #[Test]
    public function backgroundReturnsNewInstance(): void
    {
        $original = new Style();
        $modified = $original->withBackground(Color::ansi(AnsiColor::Blue));

        self::assertNull($original->background);
        self::assertNotNull($modified->background);
        self::assertNotSame($original, $modified);
    }

    #[Test]
    public function boldAddsAttribute(): void
    {
        $style = (new Style())->bold();

        self::assertSame(Attr::BOLD, $style->attributes);
    }

    #[Test]
    public function settingTheAttributesReplacesTheOnesBefore(): void
    {
        $style = (new Style())->bold()->italic()->withAttributes(Attr::UNDERLINE);

        self::assertSame(Attr::UNDERLINE, $style->attributes);
        self::assertSame(0, $style->withAttributes(0)->attributes);
    }

    #[Test]
    public function addingAttributesKeepsTheOnesBefore(): void
    {
        $style = (new Style())->bold()->addAttributes(Attr::ITALIC | Attr::UNDERLINE);

        self::assertSame(Attr::BOLD | Attr::ITALIC | Attr::UNDERLINE, $style->attributes);
    }

    #[Test]
    public function chainedAttributes(): void
    {
        $style = (new Style())->bold()->italic()->underline();

        self::assertSame(Attr::BOLD | Attr::ITALIC | Attr::UNDERLINE, $style->attributes);
    }

    #[Test]
    public function faint(): void
    {
        $style = (new Style())->faint();

        self::assertSame(Attr::FAINT, $style->attributes);
    }

    #[Test]
    public function reverse(): void
    {
        $style = (new Style())->reverse();

        self::assertSame(Attr::REVERSE, $style->attributes);
    }

    #[Test]
    public function strikethrough(): void
    {
        $style = (new Style())->strikethrough();

        self::assertSame(Attr::STRIKETHROUGH, $style->attributes);
    }

    #[Test]
    public function inheritFillsUnsetFields(): void
    {
        $child = new Style(foreground: Color::ansi(AnsiColor::Red));
        $parent = new Style(
            foreground: Color::ansi(AnsiColor::Blue),
            background: Color::ansi(AnsiColor::Green),
            attributes: Attr::BOLD,
        );

        $result = $child->inherit($parent);

        self::assertSame(Color::ansi(AnsiColor::Red)->value, $result->foreground->value);
        self::assertSame(Color::ansi(AnsiColor::Green)->value, $result->background->value);
        self::assertSame(Attr::BOLD, $result->attributes);
    }

    #[Test]
    public function inheritKeepsChildAttributes(): void
    {
        $child = new Style(attributes: Attr::ITALIC);
        $parent = new Style(attributes: Attr::BOLD);

        $result = $child->inherit($parent);

        self::assertSame(Attr::ITALIC, $result->attributes);
    }

    #[Test]
    public function foregroundValueReturnsIntOrZero(): void
    {
        $unset = new Style();
        self::assertSame(0, $unset->foregroundValue());

        $set = new Style(foreground: Color::ansi(AnsiColor::Red));
        self::assertGreaterThan(0, $set->foregroundValue());
        self::assertSame(Color::ansi(AnsiColor::Red)->value, $set->foregroundValue());
    }

    #[Test]
    public function backgroundValueReturnsIntOrZero(): void
    {
        $unset = new Style();
        self::assertSame(0, $unset->backgroundValue());

        $set = new Style(background: Color::ansi(AnsiColor::Cyan));
        self::assertGreaterThan(0, $set->backgroundValue());
        self::assertSame(Color::ansi(AnsiColor::Cyan)->value, $set->backgroundValue());
    }

    #[Test]
    public function applyFillsBufferArea(): void
    {
        $frame = new Frame(10, 5);
        $area = new Rect(2, 1, 4, 3);
        $foregroundColor = Color::ansi(AnsiColor::Red);
        $backgroundColor = Color::ansi(AnsiColor::Blue);
        $style = new Style(
            foreground: $foregroundColor,
            background: $backgroundColor,
            attributes: Attr::BOLD,
        );

        $style->apply($frame, $area);

        $index = 1 * 10 + 2;
        self::assertSame($foregroundColor->value, $frame->foregrounds[$index]);
        self::assertSame($backgroundColor->value, $frame->backgrounds[$index]);
        self::assertSame(Attr::BOLD, $frame->attributes[$index]);

        $outsideIndex = 0 * 10 + 0;
        self::assertSame(0, $frame->foregrounds[$outsideIndex]);
        self::assertSame(0, $frame->backgrounds[$outsideIndex]);
        self::assertSame(0, $frame->attributes[$outsideIndex]);
    }

    #[Test]
    public function fluentChaining(): void
    {
        $style = (new Style())
            ->withForeground(Color::hex('FF0000'))
            ->withBackground(Color::hex('0000FF'))
            ->bold()
            ->italic();

        self::assertSame(Color::hex('FF0000')->value, $style->foreground->value);
        self::assertSame(Color::hex('0000FF')->value, $style->background->value);
        self::assertSame(Attr::BOLD | Attr::ITALIC, $style->attributes);
    }

    #[Test]
    public function inheritPreservesChildBackgroundOverParent(): void
    {
        $childBackground = Color::ansi(AnsiColor::Red);
        $parentBackground = Color::ansi(AnsiColor::Blue);

        $child = new Style(background: $childBackground);
        $parent = new Style(background: $parentBackground);

        $result = $child->inherit($parent);

        self::assertSame($childBackground->value, $result->background->value);
    }

    #[Test]
    public function nullSafeForegroundAccessDoesNotThrowOnNull(): void
    {
        $style = new Style(foreground: null, background: Color::ansi(AnsiColor::Red));

        self::assertSame(0, $style->foregroundValue());
        self::assertNotSame(0, $style->backgroundValue());
    }

    #[Test]
    public function nullSafeBackgroundAccessDoesNotThrowOnNull(): void
    {
        $style = new Style(foreground: Color::ansi(AnsiColor::Blue), background: null);

        self::assertNotSame(0, $style->foregroundValue());
        self::assertSame(0, $style->backgroundValue());
    }

    #[Test]
    public function aPanelWithNoFillOfItsOwnLeavesAnInkAsItIs(): void
    {
        $ink = new Style(foreground: Color::hex('5C6370'), attributes: Attr::ITALIC);

        $placed = $ink->readableOn(new Style(foreground: Color::hex('ABB2BF')));

        self::assertSame($ink->foregroundValue(), $placed->foregroundValue());
        self::assertSame(0, $placed->backgroundValue(), 'nothing to sit on, so nothing is claimed');
    }

    #[Test]
    public function aStyleWithNoInkOfItsOwnStillJoinsThePanelBackground(): void
    {
        $panel = new Style(background: Color::hex('3E4451'));

        $placed = (new Style(attributes: Attr::BOLD))->readableOn($panel);

        self::assertNull($placed->foreground, 'no ink to lift');
        self::assertSame($panel->backgroundValue(), $placed->backgroundValue());
    }

    #[Test]
    public function anInkPlacedOnAPanelTakesItsBackgroundAndStaysReadable(): void
    {
        $panel = new Style(background: Color::hex('3E4451'));
        $dim = new Style(foreground: Color::hex('5C6370'), attributes: Attr::ITALIC);

        $placed = $dim->readableOn($panel);

        self::assertSame($panel->backgroundValue(), $placed->backgroundValue(), 'the row keeps its fill');
        self::assertNotNull($placed->foreground);
        self::assertGreaterThanOrEqual(4.5, $placed->foreground->contrastRatio(Color::hex('3E4451')));
        self::assertSame(Attr::ITALIC, $placed->attributes);
    }

    #[Test]
    public function anInkPlacedOnAPanelKeepsItsOwnColourOverThePanelInk(): void
    {
        $panel = new Style(foreground: Color::hex('282A36'), background: Color::hex('FFFFFF'));

        $placed = (new Style(foreground: Color::hex('FF0000')))->readableOn($panel, 1.0);

        self::assertEquals(Color::hex('FF0000'), $placed->foreground);
    }
}
