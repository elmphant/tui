<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Testing\ScreenBuffer;
use Elmphant\Tui\Widget\DropdownPopup;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DropdownPopupRenderTest extends TestCase
{
    #[Test]
    public function drawsTheQueryAboveTheOptionsItLeft(): void
    {
        $screen = self::render(DropdownPopup::of(['yaml', 'yml'], [], 0, 'ya', 0));

        self::assertStringContainsString('ya', $screen->getLine(1), 'The filter row');
        self::assertStringContainsString('yaml', $screen->getLine(3), 'and the options below the divider');
    }

    #[Test]
    public function drawsTheWindowStartingAtItsScrollOffset(): void
    {
        $options = array_map(static fn (int $index): string => 'option' . $index, range(1, 20));

        $screen = self::render(DropdownPopup::of($options, [], 5, '', 5));

        self::assertStringContainsString('option6', $screen->getLine(3), 'The sixth option is the first drawn');
        self::assertStringNotContainsString('option1 ', $screen->getPlainText());
    }

    #[Test]
    public function drawsTheLabelInsteadOfTheRawOptionValue(): void
    {
        $screen = self::render(DropdownPopup::of(['yml'], ['yml' => 'YAML file'], 0, '', 0));

        self::assertStringContainsString('YAML file', $screen->getLine(3));
        self::assertStringNotContainsString('yml', $screen->getLine(3));
    }

    #[Test]
    public function leavesTheBottomBorderStyledWhenThereIsNoRoomForADivider(): void
    {
        $frame = new Frame(30, 10);
        $context = new RenderContext(Theme::dark());

        DropdownPopup::of(['yaml'], [], 0, '', 0)->render($frame, new Rect(0, 0, 8, 3), $context);

        $bottomBorderRow = 2;
        $bottomBorderIndex = $bottomBorderRow * $frame->width + 1;

        self::assertSame(
            $context->theme->borderStyle->foregroundValue(),
            $frame->foreground($bottomBorderIndex),
            'The bottom border keeps its own colour instead of a divider drawn over it',
        );
    }

    private static function render(DropdownPopup $popup): ScreenBuffer
    {
        $frame = new Frame(30, 10);
        $popup->render($frame, new Rect(0, 0, 20, 7), new RenderContext(Theme::dark()));

        return ScreenBuffer::fromFrame($frame);
    }
}
