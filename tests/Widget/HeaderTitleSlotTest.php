<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Widget\Header;
use Elmphant\Tui\Widget\Text;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The title is the bar's default content, not a fourth slot. It centres when
 * the centre is free and moves to the left when a centre slot takes that place,
 * because a caller-supplied title disappearing is never the useful answer.
 */
final class HeaderTitleSlotTest extends TestCase
{
    #[Test]
    public function aTitleAloneIsCentred(): void
    {
        $drawn = self::draw(Header::new('SKYLINE'));

        self::assertStringContainsString('SKYLINE', $drawn);
        self::assertStringStartsWith('    ', $drawn);
    }

    #[Test]
    public function aCentreSlotDoesNotSwallowTheTitle(): void
    {
        $drawn = self::draw(Header::new('SKYLINE')->withCenter(Text::new('status')));

        self::assertStringContainsString('SKYLINE', $drawn);
        self::assertStringContainsString('status', $drawn);
    }

    #[Test]
    public function aDisplacedTitleMovesToTheLeft(): void
    {
        self::assertStringStartsWith('SKYLINE', self::draw(Header::new('SKYLINE')->withCenter(Text::new('status'))));
    }

    #[Test]
    public function anExplicitLeftSlotOutranksTheTitle(): void
    {
        $drawn = self::draw(Header::new('SKYLINE')->withCenter(Text::new('status'))->withLeft(Text::new('branch')));

        self::assertStringStartsWith('branch', $drawn);
        self::assertStringContainsString('status', $drawn);
    }

    #[Test]
    public function aLeftSlotAloneLeavesTheTitleCentred(): void
    {
        $drawn = self::draw(Header::new('SKYLINE')->withLeft(Text::new('branch')));

        self::assertStringStartsWith('branch', $drawn);
        self::assertStringContainsString('SKYLINE', $drawn);
    }

    private static function draw(Header $header, int $width = 40): string
    {
        $frame = new Frame($width, 1);
        $header->render($frame, Rect::fromSize($width, 1), new RenderContext(Theme::dark()));

        return implode('', $frame->characters);
    }
}
