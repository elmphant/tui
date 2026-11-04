<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Rendering;

use Closure;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\FocusState;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Context\FocusPath;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Context\RenderPass;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Terminal\ColorProfile;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Structure\Panel;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RenderContextTest extends TestCase
{
    #[Test]
    public function aContextCarriesTheColourDepthItRendersFor(): void
    {
        $context = new RenderContext(Theme::dark(), ColorProfile::Ansi);

        self::assertSame(ColorProfile::Ansi, $context->colorProfile);
    }

    #[Test]
    public function aWidgetCanAskWhichComponentHoldsTheKeyboard(): void
    {
        $pass = RenderPass::new(Theme::dark());
        $pass->focus->focusOn(FocusState::on(ComponentId::named('filter/0')));

        self::assertEquals(ComponentId::named('filter/0'), $pass->context->focusedComponent());
    }

    #[Test]
    public function aComponentWhoseIdOnlyBeginsLikeTheFocusedOneDoesNotHoldTheKeyboard(): void
    {
        $pass = RenderPass::new(Theme::dark());
        $pass->focus->focusOn(FocusState::on(ComponentId::named('tabs10')));
        $held = null;

        $pass->context->withinComponent(ComponentId::named('tabs1'), static function () use ($pass, &$held): void {
            $held = $pass->context->hasFocus();
        });

        self::assertFalse($held);
    }

    #[Test]
    public function eachChildLevelReadsTheNextSegmentOfTheFocusedId(): void
    {
        $pass = RenderPass::new(Theme::dark());
        $pass->focus->focusOn(FocusState::on(ComponentId::named('form/0/1')));
        $indexes = [];

        $pass->context->withinComponent(ComponentId::named('form'), static function () use ($pass, &$indexes): void {
            $indexes[] = $pass->context->focusedChildIndex();
            $pass->context->withinChild(0, static function () use ($pass, &$indexes): void {
                $indexes[] = $pass->context->focusedChildIndex();
            });
        });

        self::assertSame([0, 1], $indexes);
    }

    #[Test]
    public function aNewFrameForgetsWhereTheKeyboardWasInTheLastOne(): void
    {
        $pass = RenderPass::new(Theme::dark(), focus: FocusPath::nowhere());
        $pass->focus->focusOn(FocusState::on(ComponentId::named('field')));

        $pass->begin(1.0);

        self::assertNull($pass->context->focusedComponent());
    }

    #[Test]
    public function focusGivenForOneDrawEndsWithIt(): void
    {
        $pass = RenderPass::new(Theme::dark(), focus: FocusPath::nowhere());

        $pass->context->focusHere(static function (): void {
        });

        self::assertFalse($pass->context->hasFocus());
    }

    #[Test]
    public function aDrawThatThrowsLeavesTheFocusAsItFoundIt(): void
    {
        $pass = RenderPass::new(Theme::dark());
        $pass->focus->focusOn(FocusState::on(ComponentId::named('form/0/1')));
        $throw = static function (): void {
            throw new RuntimeException('a widget failed to draw');
        };
        $survive = static function (Closure $draw): void {
            try {
                $draw();
            } catch (RuntimeException) {
            }
        };

        $survive(static fn () => $pass->context->focusHere($throw));
        self::assertFalse($pass->context->hasFocus(), 'focusHere');

        $survive(static fn () => $pass->context->withinComponent(ComponentId::named('form'), $throw));
        self::assertFalse($pass->context->hasFocus(), 'withinComponent');

        $pass->context->withinComponent(ComponentId::named('form'), static function () use ($pass, $survive, $throw): void {
            $survive(static fn () => $pass->context->withoutFocus($throw));
            self::assertTrue($pass->context->hasFocus(), 'withoutFocus');

            $survive(static fn () => $pass->context->withinChild(0, $throw));
            self::assertSame(0, $pass->context->focusedChildIndex(), 'withinChild');
        });
    }

    #[Test]
    public function aHandDrawnPanelCanHandTheKeyboardToOneOfItsChildren(): void
    {
        $context = RenderContext::unfocused(Theme::dark());
        $panel = Panel::new()->add(TextInput::new())->add(TextInput::new());
        $caretRow = static function (int $child) use ($context, $panel): ?int {
            $frame = new Frame(20, 6);
            $context->focusOnChild($child, static fn () => $panel->render($frame, new Rect(0, 0, 20, 6), $context));

            return $frame->cursorPosition()?->y;
        };

        self::assertNotNull($caretRow(0));
        self::assertSame($caretRow(0) + 1, $caretRow(1));
    }

    #[Test]
    public function aContextAssumesTrueColourUntilToldOtherwise(): void
    {
        $context = new RenderContext(Theme::dark());

        self::assertSame(ColorProfile::TrueColor, $context->colorProfile);
    }
}
