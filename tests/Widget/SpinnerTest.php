<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\TickMsg;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Tests\Support\TestMessage;
use Elmphant\Tui\Widget\Interactive;
use Elmphant\Tui\Widget\Spinner;
use Elmphant\Tui\Widget\SpinnerStyle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SpinnerTest extends TestCase
{
    #[Test]
    public function implementsInteractive(): void
    {
        self::assertInstanceOf(Interactive::class, Spinner::new());
    }

    #[Test]
    public function constraintIsExactOne(): void
    {
        $spinner = Spinner::new();
        self::assertSame(1, $spinner->sizing()->rows->min);
        self::assertSame(1, $spinner->sizing()->rows->max);
    }

    #[Test]
    public function rendersFirstBrailleFrame(): void
    {
        $frame = new Frame(20, 1);
        $context = new RenderContext(Theme::dark());
        $spinner = Spinner::new();
        $spinner->render($frame, new Rect(0, 0, 20, 1), $context);

        self::assertSame('⠋', $frame->characters[0]);
    }

    #[Test]
    public function rendersLabelAfterSpinner(): void
    {
        $frame = new Frame(20, 1);
        $context = new RenderContext(Theme::dark());
        $spinner = Spinner::new('Loading...');
        $spinner->render($frame, new Rect(0, 0, 20, 1), $context);

        self::assertSame('L', $frame->characters[2]);
    }

    #[Test]
    public function theLabelFollowsTheSpinnerWhereverItIsDrawn(): void
    {
        $frame = new Frame(20, 1);
        $context = new RenderContext(Theme::dark());

        Spinner::new('Loading...')->render($frame, new Rect(5, 0, 15, 1), $context);

        self::assertSame('L', $frame->characters[7], 'a column past the mark it belongs to');
        self::assertSame(' ', $frame->characters[2], 'and nothing left behind at the left edge');
    }

    #[Test]
    public function frameTimeAdvancesTheSpinner(): void
    {
        $spinner = Spinner::new();
        $frame = new Frame(20, 1);
        $context = new RenderContext(Theme::dark());
        $context->beginFrame(0.08);

        $spinner->render($frame, new Rect(0, 0, 20, 1), $context);

        self::assertSame('⠙', $frame->characters[0]);
    }

    #[Test]
    public function frameWrapsAroundWithTime(): void
    {
        $spinner = Spinner::new();
        $frame = new Frame(20, 1);
        $context = new RenderContext(Theme::dark());
        $context->beginFrame(10 * 0.08);

        $spinner->render($frame, new Rect(0, 0, 20, 1), $context);

        self::assertSame('⠋', $frame->characters[0]);
    }

    #[Test]
    public function frameIndexFloorsPartialIntervals(): void
    {
        // 0.12 / 0.08 = 1.5: floor gives frame 1 (⠙); round/ceil would give frame 2 (⠹)
        $spinner = Spinner::new();
        $frame = new Frame(20, 1);
        $context = new RenderContext(Theme::dark());
        $context->beginFrame(0.12);

        $spinner->render($frame, new Rect(0, 0, 20, 1), $context);

        self::assertSame('⠙', $frame->characters[0]);
    }

    #[Test]
    public function withIntervalControlsAnimationSpeed(): void
    {
        $spinner = Spinner::new()->withInterval(0.5);
        $frame = new Frame(20, 1);
        $context = new RenderContext(Theme::dark());
        $context->beginFrame(0.5);

        $spinner->render($frame, new Rect(0, 0, 20, 1), $context);

        self::assertSame('⠙', $frame->characters[0]);
    }

    #[Test]
    public function renderRequestsARepaintAtTheFrameInterval(): void
    {
        $spinner = Spinner::new();
        $frame = new Frame(20, 1);
        $context = new RenderContext(Theme::dark());

        $spinner->render($frame, new Rect(0, 0, 20, 1), $context);

        self::assertSame(0.08, $context->repaintDelay());
    }

    #[Test]
    public function tickMessagesNeverChangeTheSpinner(): void
    {
        $spinner = Spinner::new();
        $result = $spinner->update(new TickMsg(0.0), new Rect(0, 0, 20, 1));

        self::assertSame($spinner, $result->model);
    }

    #[Test]
    public function lineStyleUsesLineFrames(): void
    {
        $frame = new Frame(20, 1);
        $context = new RenderContext(Theme::dark());
        $spinner = Spinner::new()->withStyle(SpinnerStyle::Line);
        $spinner->render($frame, new Rect(0, 0, 20, 1), $context);

        self::assertSame('-', $frame->characters[0]);
    }

    #[Test]
    public function ignoresNonTickMessages(): void
    {
        $spinner = Spinner::new();
        $result = $spinner->update(new TestMessage('random'), new Rect(0, 0, 20, 1));

        self::assertSame($spinner, $result->model);
    }

    #[Test]
    public function renderSkipsWhenWidthIsZero(): void
    {
        $frame = new Frame(20, 1);
        $context = new RenderContext(Theme::dark());
        $spinner = Spinner::new();

        $spinner->render($frame, new Rect(0, 0, 0, 1), $context);

        self::assertSame(' ', $frame->characters[0]);
    }

    #[Test]
    public function renderSkipsWhenHeightIsZero(): void
    {
        $frame = new Frame(20, 1);
        $context = new RenderContext(Theme::dark());
        $spinner = Spinner::new();

        $spinner->render($frame, new Rect(0, 0, 20, 0), $context);

        self::assertSame(' ', $frame->characters[0]);
    }

    #[Test]
    public function renderWorksWithMinimalArea(): void
    {
        $frame = new Frame(1, 1);
        $context = new RenderContext(Theme::dark());
        $spinner = Spinner::new();

        $spinner->render($frame, new Rect(0, 0, 1, 1), $context);

        self::assertSame('⠋', $frame->characters[0]);
    }

    #[Test]
    public function dotsStyleRendersCorrectFirstFrame(): void
    {
        $frame = new Frame(20, 1);
        $context = new RenderContext(Theme::dark());
        $spinner = Spinner::new()->withStyle(SpinnerStyle::Dots);

        $spinner->render($frame, new Rect(0, 0, 20, 1), $context);

        self::assertSame('⣾', $frame->characters[0]);
    }

    #[Test]
    public function dotsStyleHasEightFrames(): void
    {
        $frames = SpinnerStyle::Dots->frames();

        self::assertCount(8, $frames);
        self::assertSame(['⣾', '⣽', '⣻', '⢿', '⡿', '⣟', '⣯', '⣷'], $frames);
    }
}
