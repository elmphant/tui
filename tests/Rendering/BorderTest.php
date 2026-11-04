<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Rendering;

use Elmphant\Tui\Rendering\BorderGlyphs;
use Elmphant\Tui\Rendering\BorderType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BorderTest extends TestCase
{
    #[Test]
    public function roundedGlyphs(): void
    {
        $glyphs = BorderType::Rounded->glyphs();

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
        $glyphs = BorderType::Solid->glyphs();

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
        $glyphs = BorderType::Thick->glyphs();

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
        $glyphs = BorderType::Double->glyphs();

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
        $glyphs = BorderType::Dashed->glyphs();

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
        $glyphs = BorderType::Dotted->glyphs();

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
        foreach (BorderType::cases() as $style) {
            $glyphs = $style->glyphs();
            self::assertInstanceOf(BorderGlyphs::class, $glyphs, "Style {$style->name} must return BorderGlyphs");
        }
    }
}
