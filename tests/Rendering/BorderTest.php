<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Rendering;

use Elmphant\Tui\Rendering\BorderGlyphs;
use Elmphant\Tui\Rendering\BorderStyle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BorderTest extends TestCase
{
    #[Test]
    public function roundedGlyphs(): void
    {
        $glyphs = BorderStyle::Rounded->glyphs();

        self::assertSame('╭', $glyphs->topLeft);
        self::assertSame('╮', $glyphs->topRight);
        self::assertSame('╰', $glyphs->bottomLeft);
        self::assertSame('╯', $glyphs->bottomRight);
        self::assertSame('─', $glyphs->horizontal);
        self::assertSame('│', $glyphs->vertical);
    }

    #[Test]
    public function solidGlyphs(): void
    {
        $glyphs = BorderStyle::Solid->glyphs();

        self::assertSame('┌', $glyphs->topLeft);
        self::assertSame('┐', $glyphs->topRight);
        self::assertSame('└', $glyphs->bottomLeft);
        self::assertSame('┘', $glyphs->bottomRight);
        self::assertSame('─', $glyphs->horizontal);
        self::assertSame('│', $glyphs->vertical);
    }

    #[Test]
    public function thickGlyphs(): void
    {
        $glyphs = BorderStyle::Thick->glyphs();

        self::assertSame('┏', $glyphs->topLeft);
        self::assertSame('┓', $glyphs->topRight);
        self::assertSame('┗', $glyphs->bottomLeft);
        self::assertSame('┛', $glyphs->bottomRight);
        self::assertSame('━', $glyphs->horizontal);
        self::assertSame('┃', $glyphs->vertical);
    }

    #[Test]
    public function doubleGlyphs(): void
    {
        $glyphs = BorderStyle::Double->glyphs();

        self::assertSame('╔', $glyphs->topLeft);
        self::assertSame('╗', $glyphs->topRight);
        self::assertSame('╚', $glyphs->bottomLeft);
        self::assertSame('╝', $glyphs->bottomRight);
        self::assertSame('═', $glyphs->horizontal);
        self::assertSame('║', $glyphs->vertical);
    }

    #[Test]
    public function dashedGlyphs(): void
    {
        $glyphs = BorderStyle::Dashed->glyphs();

        self::assertSame('┌', $glyphs->topLeft);
        self::assertSame('┐', $glyphs->topRight);
        self::assertSame('└', $glyphs->bottomLeft);
        self::assertSame('┘', $glyphs->bottomRight);
        self::assertSame('╌', $glyphs->horizontal);
        self::assertSame('╎', $glyphs->vertical);
    }

    #[Test]
    public function dottedGlyphs(): void
    {
        $glyphs = BorderStyle::Dotted->glyphs();

        self::assertSame('┌', $glyphs->topLeft);
        self::assertSame('┐', $glyphs->topRight);
        self::assertSame('└', $glyphs->bottomLeft);
        self::assertSame('┘', $glyphs->bottomRight);
        self::assertSame('┈', $glyphs->horizontal);
        self::assertSame('┊', $glyphs->vertical);
    }

    #[Test]
    public function customGlyphs(): void
    {
        $glyphs = new BorderGlyphs(
            topLeft: '+',
            topRight: '+',
            bottomLeft: '+',
            bottomRight: '+',
            horizontal: '-',
            vertical: '|',
            teeRight: '+',
            teeLeft: '+',
            teeDown: '+',
            teeUp: '+',
        );

        self::assertSame('+', $glyphs->topLeft);
        self::assertSame('-', $glyphs->horizontal);
        self::assertSame('|', $glyphs->vertical);
        self::assertSame('+', $glyphs->teeRight);
    }

    #[Test]
    public function allCasesReturnGlyphs(): void
    {
        foreach (BorderStyle::cases() as $style) {
            $glyphs = $style->glyphs();
            self::assertInstanceOf(BorderGlyphs::class, $glyphs, "Style {$style->name} must return BorderGlyphs");
        }
    }
}
