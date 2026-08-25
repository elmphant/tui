<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Widget\Field\EchoMode;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EchoModeTest extends TestCase
{
    #[Test]
    public function maskReturnsDotsForPassword(): void
    {
        self::assertSame('•••••', EchoMode::Password->mask('hello'));
    }

    #[Test]
    public function maskReturnsEmptyForNone(): void
    {
        self::assertSame('', EchoMode::None->mask('hello'));
    }

    #[Test]
    public function maskReturnsOriginalForNormal(): void
    {
        self::assertSame('hello', EchoMode::Normal->mask('hello'));
    }

    #[Test]
    public function maskHandlesEmptyString(): void
    {
        self::assertSame('', EchoMode::Password->mask(''));
    }

    #[Test]
    public function maskHandlesMultibyteCharacters(): void
    {
        self::assertSame('•••', EchoMode::Password->mask('äöü'));
    }

    #[Test]
    public function aComposedCharacterIsOneBulletNotTwo(): void
    {
        self::assertSame('••', EchoMode::Password->mask("a\u{0301}b"));
    }
}
