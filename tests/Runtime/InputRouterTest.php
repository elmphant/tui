<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\FloatMouseMsg;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseButton;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Message\ResizeMsg;
use Elmphant\Tui\Rendering\FloatPlacement;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Runtime\InputRouter;
use Elmphant\Tui\Widget\FloatTarget;
use Elmphant\Tui\Widget\PointerFloating;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class InputRouterTest extends TestCase
{
    #[Test]
    public function aKeyMessageIsStampedWithTheScreenRect(): void
    {
        $screen = new Rect(0, 0, 80, 24);

        $routed = InputRouter::route(new KeyMsg(Key::Up), $screen, []);

        self::assertInstanceOf(KeyMsg::class, $routed);
        self::assertSame($screen, $routed->screen);
        self::assertSame(Key::Up, $routed->key);
    }

    #[Test]
    public function aMouseMessageIsStampedWithTheScreenRect(): void
    {
        $screen = new Rect(0, 0, 80, 24);

        $routed = InputRouter::route(self::press(1, 1), $screen, []);

        self::assertInstanceOf(MouseMsg::class, $routed);
        self::assertSame($screen, $routed->screen);
    }

    #[Test]
    public function aPressInsideAPlacementIsWrappedForThatFloat(): void
    {
        $screen = new Rect(0, 0, 80, 24);
        $placement = self::placementOf(new Rect(5, 5, 10, 4));

        $routed = InputRouter::route(self::press(6, 6), $screen, [$placement]);

        self::assertInstanceOf(FloatMouseMsg::class, $routed);
        self::assertSame($placement, $routed->float);
        self::assertSame($screen, $routed->mouse->screen, 'the wrapped message must be the stamped one');
        self::assertSame(6, $routed->mouse->x);
    }

    #[Test]
    public function theTopmostPlacementWinsWhereTwoOverlap(): void
    {
        $under = self::placementOf(new Rect(0, 0, 20, 10));
        $over = self::placementOf(new Rect(5, 5, 10, 4));

        $routed = InputRouter::route(self::press(6, 6), new Rect(0, 0, 80, 24), [$under, $over]);

        self::assertInstanceOf(FloatMouseMsg::class, $routed);
        self::assertSame($over, $routed->float);
    }

    #[Test]
    public function anActionTheFloatDoesNotCaptureStaysPlain(): void
    {
        $placement = self::placementOf(new Rect(5, 5, 10, 4));

        foreach ([MouseAction::Motion, MouseAction::Release] as $action) {
            $routed = InputRouter::route(
                new MouseMsg(6, 6, $action, MouseButton::Left),
                new Rect(0, 0, 80, 24),
                [$placement],
            );

            self::assertInstanceOf(MouseMsg::class, $routed, $action->name . ' must pass beneath the float');
        }
    }

    #[Test]
    public function aFloatThatCapturesNothingDoesNotHandThePressToTheFloatBeneathIt(): void
    {
        $popup = self::placementOf(new Rect(0, 0, 20, 10));
        $tooltip = self::placementOf(new Rect(5, 5, 10, 4), captures: []);

        $routed = InputRouter::route(self::press(6, 6), new Rect(0, 0, 80, 24), [$popup, $tooltip]);

        self::assertInstanceOf(MouseMsg::class, $routed, 'the float on top owns that cell, so nothing below may claim it');
    }

    #[Test]
    public function aPressOutsideEveryPlacementStaysPlain(): void
    {
        $placement = self::placementOf(new Rect(5, 5, 10, 4));

        $routed = InputRouter::route(self::press(1, 1), new Rect(0, 0, 80, 24), [$placement]);

        self::assertInstanceOf(MouseMsg::class, $routed);
    }

    #[Test]
    public function aWheelInsideAPlacementIsWrapped(): void
    {
        $placement = self::placementOf(new Rect(5, 5, 10, 4));

        $routed = InputRouter::route(
            new MouseMsg(6, 6, MouseAction::WheelDown, MouseButton::None, 3),
            new Rect(0, 0, 80, 24),
            [$placement],
        );

        self::assertInstanceOf(FloatMouseMsg::class, $routed);
        self::assertSame(3, $routed->mouse->repeat, 'a merged wheel run keeps its notch count');
    }

    #[Test]
    public function aMessageThatCarriesNoPointerIsUntouched(): void
    {
        $resize = new ResizeMsg(80, 24);

        self::assertSame($resize, InputRouter::route($resize, new Rect(0, 0, 80, 24), []));
    }

    private static function press(int $x, int $y): MouseMsg
    {
        return new MouseMsg($x, $y, MouseAction::Press, MouseButton::Left);
    }

    /**
     * @param list<MouseAction>|null $captures null means the default set: press and both wheels
     */
    private static function placementOf(Rect $placement, ?array $captures = null): FloatPlacement
    {
        $captures ??= [MouseAction::Press, MouseAction::WheelUp, MouseAction::WheelDown];

        $float = new class($captures) implements PointerFloating {
            private readonly FloatTarget $target;

            /**
             * @param list<MouseAction> $captures
             */
            public function __construct(private readonly array $captures)
            {
                $this->target = new FloatTarget();
            }

            public function target(): FloatTarget
            {
                return $this->target;
            }

            public function captures(MouseAction $action): bool
            {
                return in_array($action, $this->captures, true);
            }

            public function placement(Rect $anchor, Rect $viewport): Rect
            {
                return $anchor;
            }

            public function constraint(): Constraint
            {
                return Constraint::exact(1);
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
            }
        };

        return new FloatPlacement($float, $placement, $placement);
    }
}
