<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Input;

use Elmphant\Tui\Input\ColorProfile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ColorProfileTest extends TestCase
{
    /**
     * @return iterable<string, array{ColorProfile, bool}>
     */
    public static function gradationPerProfile(): iterable
    {
        yield 'truecolor' => [ColorProfile::TrueColor, true];
        yield '256 colours' => [ColorProfile::Ansi256, true];
        yield '16 colours' => [ColorProfile::Ansi, false];
        yield 'no colour' => [ColorProfile::Ascii, false];
    }

    #[Test]
    #[DataProvider('gradationPerProfile')]
    public function onlyTheDeeperProfilesCanShowAFade(ColorProfile $profile, bool $expected): void
    {
        self::assertSame($expected, $profile->blendsSmoothly());
    }

    /**
     * @return iterable<string, array{ColorProfile, bool}>
     */
    public static function paletteKnowledgePerProfile(): iterable
    {
        yield 'truecolor' => [ColorProfile::TrueColor, true];
        yield '256 colours' => [ColorProfile::Ansi256, true];
        yield '16 colours' => [ColorProfile::Ansi, false];
        yield 'no colour' => [ColorProfile::Ascii, false];
    }

    #[Test]
    #[DataProvider('paletteKnowledgePerProfile')]
    public function onlyAKnownPaletteKeepsAContrastRatio(ColorProfile $profile, bool $expected): void
    {
        self::assertSame($expected, $profile->keepsContrast());
    }
}
