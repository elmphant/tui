<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Rendering;

use Elmphant\Tui\Rendering\Style;
use Elmphant\Tui\Rendering\StyleToken;
use Elmphant\Tui\Rendering\Theme;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StyleTokenTest extends TestCase
{
    /**
     * @return iterable<string, array{StyleToken, callable(Theme): Style}>
     */
    public static function tokenToThemeField(): iterable
    {
        yield 'base' => [StyleToken::Base, static fn (Theme $theme): Style => $theme->base];
        yield 'accent' => [StyleToken::Accent, static fn (Theme $theme): Style => $theme->accent];
        yield 'dim' => [StyleToken::Dim, static fn (Theme $theme): Style => $theme->dim];
        yield 'error' => [StyleToken::Error, static fn (Theme $theme): Style => $theme->error];
        yield 'success' => [StyleToken::Success, static fn (Theme $theme): Style => $theme->success];
        yield 'warning' => [StyleToken::Warning, static fn (Theme $theme): Style => $theme->warning];
        yield 'info' => [StyleToken::Info, static fn (Theme $theme): Style => $theme->info];
        yield 'title' => [StyleToken::Title, static fn (Theme $theme): Style => $theme->title];
    }

    /**
     * @param callable(Theme): Style $field
     */
    #[Test]
    #[DataProvider('tokenToThemeField')]
    public function eachTokenResolvesToItsOwnThemeField(StyleToken $token, callable $field): void
    {
        $theme = Theme::dark();

        self::assertSame($field($theme), $token->resolve($theme));
    }

    #[Test]
    public function theSameTokenResolvesDifferentlyPerTheme(): void
    {
        $dark = StyleToken::Accent->resolve(Theme::dark());
        $light = StyleToken::Accent->resolve(Theme::light());

        self::assertNotEquals($dark, $light);
    }
}
