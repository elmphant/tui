<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Rendering;

use Closure;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\FocusState;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Context\Drawable;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Context\RenderPass;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Style;
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
    public function anUnfocusedPassDrawsAComponentWithoutTheKeyboard(): void
    {
        $pass = RenderPass::unfocused(Theme::dark());
        $focused = null;

        $pass->context->withinComponent(ComponentId::named('field'), static function () use ($pass, &$focused): void {
            $focused = $pass->context->hasFocus();
        });

        self::assertFalse($focused);
    }

    #[Test]
    public function aNewFrameForgetsWhereTheKeyboardWasInTheLastOne(): void
    {
        $pass = RenderPass::unfocused(Theme::dark());
        $pass->focus->focusOn(FocusState::on(ComponentId::named('field')));

        $pass->begin(1.0);

        self::assertNull($pass->context->focusedComponent());
    }

    #[Test]
    public function focusGivenForOneDrawEndsWithIt(): void
    {
        $pass = RenderPass::unfocused(Theme::dark());

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
            $survive(static fn () => $pass->context->drawUnfocused($throw));
            self::assertTrue($pass->context->hasFocus(), 'drawUnfocused');

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
    public function onlyTheChildThePathNamesIsDrawnWithTheKeyboard(): void
    {
        $pass = RenderPass::new(Theme::dark());
        $pass->focus->focusOn(FocusState::on(ComponentId::named('form/1')));
        $first = new FocusRecordingPart();
        $second = new FocusRecordingPart();

        $pass->context->withinComponent(ComponentId::named('form'), static function () use ($pass, $first, $second): void {
            $frame = new Frame(4, 2);
            $pass->context->drawChild(0, $first, $frame, new Rect(0, 0, 4, 1));
            $pass->context->drawChild(1, $second, $frame, new Rect(0, 1, 4, 1));
        });

        self::assertSame([false], $first->focus);
        self::assertSame([true], $second->focus);
    }

    #[Test]
    public function aPartDrawnWithoutAnIndexKeepsTheFocusItWasDrawnUnder(): void
    {
        $context = RenderContext::focused(Theme::dark());
        $part = new FocusRecordingPart();

        $context->drawChild(null, $part, new Frame(4, 1), new Rect(0, 0, 4, 1));
        $context->draw($part, new Frame(4, 1), new Rect(0, 0, 4, 1));

        self::assertSame([true, true], $part->focus);
    }

    #[Test]
    public function aDrawnPartPaintsTheAreaItIsGiven(): void
    {
        $frame = new Frame(4, 2);

        RenderContext::unfocused(Theme::dark())->draw(new FocusRecordingPart(), $frame, new Rect(1, 1, 3, 1));

        self::assertSame(' x ', $frame->textOf(1, 0, 3));
    }

    #[Test]
    public function aContextAssumesTrueColourUntilToldOtherwise(): void
    {
        $context = new RenderContext(Theme::dark());

        self::assertSame(ColorProfile::TrueColor, $context->colorProfile);
    }
}

final class FocusRecordingPart implements Drawable
{
    /**
     * @var list<bool>
     */
    public array $focus = [];

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        $this->focus[] = $context->hasFocus();
        $frame->putStyled($area->x, $area->y, 'x', new Style());
    }
}
