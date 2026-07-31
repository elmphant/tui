<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Rendering;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Attr;
use Elmphant\Tui\Rendering\Color;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Style;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StyleTest extends TestCase
{
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
        $modified = $original->foreground(Color::named('red'));

        self::assertNull($original->foreground);
        self::assertNotNull($modified->foreground);
        self::assertNotSame($original, $modified);
    }

    #[Test]
    public function backgroundReturnsNewInstance(): void
    {
        $original = new Style();
        $modified = $original->background(Color::named('blue'));

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
        $child = new Style(foreground: Color::named('red'));
        $parent = new Style(
            foreground: Color::named('blue'),
            background: Color::named('green'),
            attributes: Attr::BOLD,
        );

        $result = $child->inherit($parent);

        self::assertSame(Color::named('red')->value, $result->foreground->value);
        self::assertSame(Color::named('green')->value, $result->background->value);
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

        $set = new Style(foreground: Color::named('red'));
        self::assertGreaterThan(0, $set->foregroundValue());
        self::assertSame(Color::named('red')->value, $set->foregroundValue());
    }

    #[Test]
    public function backgroundValueReturnsIntOrZero(): void
    {
        $unset = new Style();
        self::assertSame(0, $unset->backgroundValue());

        $set = new Style(background: Color::named('cyan'));
        self::assertGreaterThan(0, $set->backgroundValue());
        self::assertSame(Color::named('cyan')->value, $set->backgroundValue());
    }

    #[Test]
    public function applyFillsBufferArea(): void
    {
        $frame = new Frame(10, 5);
        $area = new Rect(2, 1, 4, 3);
        $foregroundColor = Color::named('red');
        $backgroundColor = Color::named('blue');
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
            ->foreground(Color::hex('FF0000'))
            ->background(Color::hex('0000FF'))
            ->bold()
            ->italic();

        self::assertSame(Color::hex('FF0000')->value, $style->foreground->value);
        self::assertSame(Color::hex('0000FF')->value, $style->background->value);
        self::assertSame(Attr::BOLD | Attr::ITALIC, $style->attributes);
    }

    #[Test]
    public function inheritPreservesChildBackgroundOverParent(): void
    {
        $childBackground = Color::named('red');
        $parentBackground = Color::named('blue');

        $child = new Style(background: $childBackground);
        $parent = new Style(background: $parentBackground);

        $result = $child->inherit($parent);

        self::assertSame($childBackground->value, $result->background->value);
    }

    #[Test]
    public function nullSafeForegroundAccessDoesNotThrowOnNull(): void
    {
        $style = new Style(foreground: null, background: Color::named('red'));

        self::assertSame(0, $style->foregroundValue());
        self::assertNotSame(0, $style->backgroundValue());
    }

    #[Test]
    public function nullSafeBackgroundAccessDoesNotThrowOnNull(): void
    {
        $style = new Style(foreground: Color::named('blue'), background: null);

        self::assertNotSame(0, $style->foregroundValue());
        self::assertSame(0, $style->backgroundValue());
    }
}
