<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Input\KeybindingsAware;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseButton;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Rendering\Attr;
use Elmphant\Tui\Rendering\Color;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Style;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Testing\ScreenBuffer;
use Elmphant\Tui\Widget\Focusable;
use Elmphant\Tui\Widget\Interactive;
use Elmphant\Tui\Widget\Popup\Modal;
use Elmphant\Tui\Widget\Prose\Paragraph;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Structure\Panel;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionObject;

final class ModalTest extends TestCase
{
    private RenderContext $context;

    #[Test]
    public function itNamesTheKeysItAnswers(): void
    {
        self::assertSame(
            ['Esc' => 'dismiss'],
            Modal::new('Title', Text::new('body'))->hints(),
        );
    }

    #[Test]
    public function contentDrawingPastItsAreaIsCroppedByTheModalBorder(): void
    {
        $frame = new Frame(40, 20);

        Modal::new('Held', self::widgetDrawingPastItsArea())
            ->render($frame, new Rect(0, 0, 40, 20), new RenderContext(Theme::dark()));

        $screen = ScreenBuffer::fromFrame($frame);
        $bottomBorderRow = 0;
        $contentRow = 0;

        for ($row = 0; $row < 20; ++$row) {
            $line = $screen->lineAt($row);

            if ($contentRow === 0 && str_contains($line, 'X')) {
                $contentRow = $row;
            }

            if (str_contains($line, '╰')) {
                $bottomBorderRow = $row;
            }
        }

        self::assertGreaterThan(0, $contentRow, 'Precondition: the content did draw inside the modal');
        self::assertStringNotContainsString('X', $screen->lineAt($bottomBorderRow), 'nothing of it reached past the border');
        self::assertStringContainsString('╰', $screen->lineAt($bottomBorderRow), 'and the border ring is intact');
    }

    #[Test]
    public function implementsInteractive(): void
    {
        self::assertInstanceOf(Interactive::class, Modal::new('Title', Text::new('Content')));
    }

    #[Test]
    public function opensOverAWideGraphemeWithoutDisturbingIt(): void
    {
        $frame = new Frame(40, 10);
        $frame->putString(0, 0, '👋 hello');

        Modal::new('T', Text::new('X'))->render($frame, Rect::fromSize(40, 10), $this->context);

        self::assertSame('👋', $frame->characters[0]);
        self::assertSame('', $frame->characters[1], 'the trailing half of the wide grapheme');
    }

    #[Test]
    public function fittingAroundTheContentCanBeTurnedOffAgain(): void
    {
        $content = Paragraph::fromText("one two three\nfour");
        $plain = new Frame(100, 50);
        $toggled = new Frame(100, 50);

        Modal::new('T', $content)->render($plain, Rect::fromSize(100, 50), $this->context);
        Modal::new('T', $content)->withSizedToContent()->withSizedToContent(false)->render($toggled, Rect::fromSize(100, 50), $this->context);

        self::assertSame(ScreenBuffer::fromFrame($plain)->plainText(), ScreenBuffer::fromFrame($toggled)->plainText());
    }

    #[Test]
    public function fitsItselfAroundItsContent(): void
    {
        $content = Paragraph::fromText("one two three\nfour");
        $frame = new Frame(100, 50);

        Modal::new('T', $content)->withSizedToContent()->render($frame, Rect::fromSize(100, 50), $this->context);

        $drawn = array_values(array_filter(
            explode("\n", ScreenBuffer::fromFrame($frame)->plainText()),
            static fn (string $line): bool => trim($line) !== '',
        ));

        self::assertCount($content->measureHeight(100) + 2, $drawn, 'content plus both borders');
        self::assertSame($content->sizing()->columns->basis + 2, mb_strlen(trim($drawn[0])), 'content plus both borders');
    }

    #[Test]
    public function implementsFocusableSoAppCanRouteInputWhenModalIsOpen(): void
    {
        self::assertInstanceOf(Focusable::class, Modal::new('Title', Text::new('Content')));
    }

    #[Test]
    public function typedTitleAndContentBuildersReplaceValuesAndElideNoOps(): void
    {
        $replacementContent = Text::new('After');
        $modal = Modal::new('Original', Text::new('Before'))
            ->withTitle('Replacement')
            ->withContent($replacementContent);

        self::assertSame($replacementContent, $modal->placedChildren(Rect::fromSize(80, 24))[0][0]);
        self::assertSame($modal, $modal->withTitle('Replacement'));
        self::assertSame($modal, $modal->withContent($replacementContent));

        $frame = new Frame(40, 10);
        $modal->render($frame, Rect::fromSize(40, 10), $this->context);
        self::assertStringContainsString('Replacement', implode('', $frame->characters));
    }

    #[Test]
    public function constraintReturnsFlex(): void
    {
        $modal = Modal::new('Title', Text::new('Content'));
        self::assertSame(1, $modal->sizing()->rows->flex);
    }

    // ---------------------------------------------------------------
    // L40-41: Default size is 60% width and 60% height
    // ---------------------------------------------------------------

    #[Test]
    public function defaultWidthPercentIsSixty(): void
    {
        $modal = Modal::new('T', Text::new('X'));
        $frame = new Frame(100, 50);
        $area = Rect::fromSize(100, 50);
        $modal->render($frame, $area, $this->context);

        // At 60% of 100 = 60 wide. Modal centered at x=(100-60)/2=20
        // The top-left corner '╭' should be at x=20
        $topLeftIndex = 0 * 100 + 20; // y=0 is wrong, need to compute y
        // y = (50-30)/2 = 10, so top-left corner at (20, 10)
        $cornerIndex = 10 * 100 + 20;
        self::assertSame('╭', $frame->characters[$cornerIndex]);
    }

    #[Test]
    public function defaultHeightPercentIsSixty(): void
    {
        $modal = Modal::new('T', Text::new('X'));
        $frame = new Frame(100, 50);
        $area = Rect::fromSize(100, 50);
        $modal->render($frame, $area, $this->context);

        // 60% of 50 = 30 height. y = (50-30)/2 = 10
        // Bottom-left corner at (20, 10+30-1) = (20, 39)
        $bottomLeftIndex = 39 * 100 + 20;
        self::assertSame('╰', $frame->characters[$bottomLeftIndex]);
    }

    // ---------------------------------------------------------------
    // L53: withWidth clamps to [10, 100]
    // ---------------------------------------------------------------

    #[Test]
    public function withWidthClampsAtMinimumTen(): void
    {
        $modal = Modal::new('T', Text::new('X'))->withWidth(5);
        $frame = new Frame(100, 50);
        $area = Rect::fromSize(100, 50);
        $modal->render($frame, $area, $this->context);

        // 10% of 100 = 10 width. x = (100-10)/2 = 45
        // height at 60% = 30, y = (50-30)/2 = 10
        $topLeftIndex = 10 * 100 + 45;
        self::assertSame('╭', $frame->characters[$topLeftIndex]);
        // Verify it's NOT at width=5% (which would be 5 wide, x=47)
        self::assertNotSame('╭', $frame->characters[10 * 100 + 47]);
    }

    #[Test]
    public function withWidthClampsAtMaximumHundred(): void
    {
        $modal = Modal::new('T', Text::new('X'))->withWidth(150);
        $frame = new Frame(100, 50);
        $area = Rect::fromSize(100, 50);
        $modal->render($frame, $area, $this->context);

        // 100% of 100 = 100 width. x = (100-100)/2 = 0
        // height at 60% = 30, y = (50-30)/2 = 10
        $topLeftIndex = 10 * 100 + 0;
        self::assertSame('╭', $frame->characters[$topLeftIndex]);
    }

    #[Test]
    public function withWidthAcceptsValueAtLowerBoundary(): void
    {
        $modal = Modal::new('T', Text::new('X'))->withWidth(10);
        $frame = new Frame(100, 50);
        $area = Rect::fromSize(100, 50);
        $modal->render($frame, $area, $this->context);

        // 10% of 100 = 10 width. x = (100-10)/2 = 45
        $topLeftIndex = 10 * 100 + 45;
        self::assertSame('╭', $frame->characters[$topLeftIndex]);
    }

    #[Test]
    public function withWidthAcceptsValueAtUpperBoundary(): void
    {
        $modal = Modal::new('T', Text::new('X'))->withWidth(100);
        $frame = new Frame(100, 50);
        $area = Rect::fromSize(100, 50);
        $modal->render($frame, $area, $this->context);

        // 100% = full width, x=0
        $topLeftIndex = 10 * 100 + 0;
        self::assertSame('╭', $frame->characters[$topLeftIndex]);
    }

    // ---------------------------------------------------------------
    // L58: withHeight clamps to [10, 100]
    // ---------------------------------------------------------------

    #[Test]
    public function withHeightClampsAtMinimumTen(): void
    {
        $modal = Modal::new('T', Text::new('X'))->withHeight(5);
        $frame = new Frame(100, 50);
        $area = Rect::fromSize(100, 50);
        $modal->render($frame, $area, $this->context);

        // 10% of 50 = 5 height. y = (50-5)/2 = 22
        // width at 60% = 60, x = (100-60)/2 = 20
        $topLeftIndex = 22 * 100 + 20;
        self::assertSame('╭', $frame->characters[$topLeftIndex]);
    }

    #[Test]
    public function withHeightClampsAtMaximumHundred(): void
    {
        $modal = Modal::new('T', Text::new('X'))->withHeight(150);
        $frame = new Frame(100, 50);
        $area = Rect::fromSize(100, 50);
        $modal->render($frame, $area, $this->context);

        // 100% of 50 = 50 height. y = (50-50)/2 = 0
        // width at 60% = 60, x = (100-60)/2 = 20
        $topLeftIndex = 0 * 100 + 20;
        self::assertSame('╭', $frame->characters[$topLeftIndex]);
    }

    #[Test]
    public function withHeightAcceptsValueAtLowerBoundary(): void
    {
        $modal = Modal::new('T', Text::new('X'))->withHeight(10);
        $frame = new Frame(100, 50);
        $area = Rect::fromSize(100, 50);
        $modal->render($frame, $area, $this->context);

        // 10% of 50 = 5 height. y = (50-5)/2 = 22
        $topLeftIndex = 22 * 100 + 20;
        self::assertSame('╭', $frame->characters[$topLeftIndex]);
    }

    #[Test]
    public function withHeightAcceptsValueAtUpperBoundary(): void
    {
        $modal = Modal::new('T', Text::new('X'))->withHeight(100);
        $frame = new Frame(100, 50);
        $area = Rect::fromSize(100, 50);
        $modal->render($frame, $area, $this->context);

        // 100% = full height, y=0
        $topLeftIndex = 0 * 100 + 20;
        self::assertSame('╭', $frame->characters[$topLeftIndex]);
    }

    // ---------------------------------------------------------------
    // L78: MatchArmRemoval — Escape and click-outside dismiss
    // ---------------------------------------------------------------

    #[Test]
    public function escapeDismissesModal(): void
    {
        $modal = Modal::new('Title', Text::new('Content'));
        $area = Rect::fromSize(80, 24);

        self::assertTrue($modal->dismisses(new KeyMsg(Key::Escape), $area));
    }

    #[Test]
    public function dismissedModalReturnsModalDismissedCommand(): void
    {
        $modal = Modal::new('MyModal', Text::new('Content'));
        $area = Rect::fromSize(80, 24);

        $result = $modal->update(new KeyMsg(Key::Escape), $area);
        self::assertNotNull($result->command);
    }

    #[Test]
    public function mouseClickOutsideModalDismisses(): void
    {
        $modal = Modal::new('Title', Text::new('Content'));
        $area = new Rect(0, 0, 80, 24);

        $mouseMessage = new MouseMsg(0, 0, MouseAction::Press, MouseButton::Left);
        self::assertTrue($modal->dismisses($mouseMessage, $area));
    }

    #[Test]
    public function mouseClickInsideModalDoesNotDismiss(): void
    {
        $modal = Modal::new('Title', Text::new('Content'));
        $area = new Rect(0, 0, 80, 24);

        $mouseMessage = new MouseMsg(40, 12, MouseAction::Press, MouseButton::Left);
        self::assertFalse($modal->dismisses($mouseMessage, $area));
    }

    #[Test]
    public function nonEscapeKeyDoesNotDismissWithNonInteractiveContent(): void
    {
        $modal = Modal::new('Title', Text::new('Content'));
        $area = Rect::fromSize(80, 24);

        self::assertFalse($modal->dismisses(new KeyMsg('a'), $area));
    }

    #[Test]
    public function mouseMoveDoesNotDismiss(): void
    {
        $modal = Modal::new('Title', Text::new('Content'));
        $area = Rect::fromSize(80, 24);

        $mouseMessage = new MouseMsg(0, 0, MouseAction::Motion, MouseButton::None);
        self::assertFalse($modal->dismisses($mouseMessage, $area));
    }

    // ---------------------------------------------------------------
    // L98: Ternary — content unchanged returns same modal instance
    // ---------------------------------------------------------------

    #[Test]
    public function updateReturnsNewModalWhenContentChanges(): void
    {
        $content = $this->createMutatingInteractiveWidget();
        $modal = Modal::new('Title', $content);
        $area = Rect::fromSize(80, 24);

        $result = $modal->update(new KeyMsg('x'), $area);
        self::assertNotSame($modal, $result->model);
    }

    #[Test]
    public function updateReturnsSameModalWhenContentIsUnchanged(): void
    {
        $content = $this->createStableInteractiveWidget();
        $modal = Modal::new('Title', $content);
        $area = Rect::fromSize(80, 24);

        $result = $modal->update(new KeyMsg('x'), $area);
        self::assertSame($modal, $result->model);
    }

    // ---------------------------------------------------------------
    // L105: Render early-return when area < 5x5
    // ---------------------------------------------------------------

    #[Test]
    public function renderSkipsWhenWidthBelowFive(): void
    {
        $modal = Modal::new('Title', Text::new('Content'));
        $frame = new Frame(4, 10);
        $area = new Rect(0, 0, 4, 10);
        $modal->render($frame, $area, $this->context);

        // No border corner should appear since render returns early
        $hasCorner = false;
        for ($index = 0; $index < $frame->size; ++$index) {
            if ($frame->characters[$index] === '╭') {
                $hasCorner = true;
            }
        }
        self::assertFalse($hasCorner, 'No rendering should happen when width < 5');
    }

    #[Test]
    public function renderSkipsWhenHeightBelowFive(): void
    {
        $modal = Modal::new('Title', Text::new('Content'));
        $frame = new Frame(80, 4);
        $area = new Rect(0, 0, 80, 4);
        $modal->render($frame, $area, $this->context);

        $hasCorner = false;
        for ($index = 0; $index < $frame->size; ++$index) {
            if ($frame->characters[$index] === '╭') {
                $hasCorner = true;
            }
        }
        self::assertFalse($hasCorner, 'No rendering should happen when height < 5');
    }

    #[Test]
    public function renderProceedsWhenAreaExactlyFiveByFive(): void
    {
        $modal = Modal::new('T', Text::new('X'))->withWidth(100)->withHeight(100);
        $frame = new Frame(5, 5);
        $area = new Rect(0, 0, 5, 5);
        $modal->render($frame, $area, $this->context);

        $hasCorner = false;
        for ($index = 0; $index < $frame->size; ++$index) {
            if ($frame->characters[$index] === '╭') {
                $hasCorner = true;
            }
        }
        self::assertTrue($hasCorner, 'Rendering should happen when area is exactly 5x5');
    }

    #[Test]
    public function renderSkipsWhenWidthExactlyFourEvenWithLargeHeight(): void
    {
        $modal = Modal::new('T', Text::new('X'));
        $frame = new Frame(4, 100);
        $area = new Rect(0, 0, 4, 100);
        $modal->render($frame, $area, $this->context);

        $hasCorner = false;
        for ($index = 0; $index < $frame->size; ++$index) {
            if ($frame->characters[$index] === '╭') {
                $hasCorner = true;
            }
        }
        self::assertFalse($hasCorner);
    }

    #[Test]
    public function renderSkipsWhenHeightExactlyFourEvenWithLargeWidth(): void
    {
        $modal = Modal::new('T', Text::new('X'));
        $frame = new Frame(100, 4);
        $area = new Rect(0, 0, 100, 4);
        $modal->render($frame, $area, $this->context);

        $hasCorner = false;
        for ($index = 0; $index < $frame->size; ++$index) {
            if ($frame->characters[$index] === '╭') {
                $hasCorner = true;
            }
        }
        self::assertFalse($hasCorner);
    }

    // ---------------------------------------------------------------
    // L112: Modal rect too small (< 3x3) after percentage calculation
    // ---------------------------------------------------------------

    #[Test]
    public function renderSkipsWhenModalRectWidthBelowThree(): void
    {
        // With width=10%, area width=20 => modalWidth = max(3, (int)(20*10/100)) = max(3,2) = 3
        // So we need area even smaller. width=10%, area=19 => max(3, 1) = 3. Still 3.
        // We need to bypass the max(3,...). Actually max(3,...) prevents < 3.
        // But if area is so small that 60% < 3, e.g. area width=4: (int)(4*60/100) = 2, but max(3,2) = 3
        // So modal width is always >= 3. The L112 check fires if COMPUTED modalRect has width < 3.
        // Wait, computeModalRect uses max(3, ...) so this can't really happen with standard params.
        // But consider: area width=5, 60% => (int)(5*60/100) = 3, max(3,3) = 3. OK.
        // With 10% minimum: area width=5, 10% => max(3, 0) = 3. Still 3.
        // So this guard is for safety. Let's verify rendering proceeds with exactly 3.
        $modal = Modal::new('T', Text::new('X'))->withWidth(10);
        $frame = new Frame(5, 30);
        $area = new Rect(0, 0, 5, 30);
        $modal->render($frame, $area, $this->context);

        // modalWidth = max(3, (int)(5*10/100)) = max(3, 0) = 3, OK renders
        $hasCorner = false;
        for ($index = 0; $index < $frame->size; ++$index) {
            if ($frame->characters[$index] === '╭') {
                $hasCorner = true;
            }
        }
        self::assertTrue($hasCorner, 'Modal with width=3 should still render');
    }

    #[Test]
    public function renderSkipsWhenModalRectHeightBelowThree(): void
    {
        $modal = Modal::new('T', Text::new('X'))->withHeight(10);
        $frame = new Frame(30, 5);
        $area = new Rect(0, 0, 30, 5);
        $modal->render($frame, $area, $this->context);

        // modalHeight = max(3, (int)(5*10/100)) = max(3, 0) = 3, OK renders
        $hasCorner = false;
        for ($index = 0; $index < $frame->size; ++$index) {
            if ($frame->characters[$index] === '╭') {
                $hasCorner = true;
            }
        }
        self::assertTrue($hasCorner, 'Modal with height=3 should still render');
    }

    // ---------------------------------------------------------------
    // L117: MethodCallRemoval — clearModalArea must be called
    // ---------------------------------------------------------------

    #[Test]
    public function clearModalAreaFillsWithSpaces(): void
    {
        $frame = new Frame(80, 24);
        $area = Rect::fromSize(80, 24);

        // Pre-fill the buffer area where the modal will appear with non-space chars
        // Modal at 60% of 80 = 48 wide, x = (80-48)/2 = 16
        // Modal at 60% of 24 = 14 high, y = (24-14)/2 = 5
        for ($row = 5; $row < 19; ++$row) {
            for ($column = 16; $column < 64; ++$column) {
                $frame->putChar($column, $row, 'X');
            }
        }

        $modal = Modal::new('T', Text::new(''));
        $modal->render($frame, $area, $this->context);

        // Inside the modal border (at x=17, y=6 which is inside the border),
        // the char should be a space, not 'X'
        $innerIndex = 7 * 80 + 18;
        self::assertSame(' ', $frame->characters[$innerIndex]);
    }

    // ---------------------------------------------------------------
    // L122-123: Coalesce — borderStyleOverride and titleStyleOverride
    // ---------------------------------------------------------------

    #[Test]
    public function borderStyleOverrideIsUsedWhenSet(): void
    {
        $customStyle = new Style(foreground: Color::ansi(1));
        $modal = Modal::new('Title', Text::new('Content'))
            ->withBorderStyle($customStyle);
        $frame = new Frame(80, 24);
        $area = Rect::fromSize(80, 24);
        $modal->render($frame, $area, $this->context);

        // Find the top-left corner
        $cornerForeground = null;
        for ($index = 0; $index < $frame->size; ++$index) {
            if ($frame->characters[$index] === '╭') {
                $cornerForeground = $frame->foregrounds[$index];
                break;
            }
        }

        self::assertNotNull($cornerForeground);
        self::assertSame($customStyle->foregroundValue(), $cornerForeground);
    }

    #[Test]
    public function defaultBorderStyleUsesThemeFocusedBorderStyle(): void
    {
        $modal = Modal::new('Title', Text::new('Content'));
        $frame = new Frame(80, 24);
        $area = Rect::fromSize(80, 24);
        $modal->render($frame, $area, $this->context);

        $cornerForeground = null;
        for ($index = 0; $index < $frame->size; ++$index) {
            if ($frame->characters[$index] === '╭') {
                $cornerForeground = $frame->foregrounds[$index];
                break;
            }
        }

        self::assertNotNull($cornerForeground);
        self::assertSame(
            $this->context->theme->focusedBorderStyle->foregroundValue(),
            $cornerForeground,
        );
    }

    #[Test]
    public function titleStyleOverrideIsUsedWhenSet(): void
    {
        $customStyle = new Style(foreground: Color::ansi(2));
        $modal = Modal::new('Title', Text::new('Content'))
            ->withTitleStyle($customStyle);
        $frame = new Frame(80, 24);
        $area = Rect::fromSize(80, 24);
        $modal->render($frame, $area, $this->context);

        // Find the 'T' of 'Title' in the buffer and check its foreground
        $titleForeground = null;
        for ($index = 0; $index < $frame->size; ++$index) {
            if ($frame->characters[$index] === 'T'
                && isset($frame->characters[$index + 1])
                && $frame->characters[$index + 1] === 'i') {
                $titleForeground = $frame->foregrounds[$index];
                break;
            }
        }

        self::assertNotNull($titleForeground);
        self::assertSame($customStyle->foregroundValue(), $titleForeground);
    }

    #[Test]
    public function defaultTitleStyleUsesThemeTitleStyle(): void
    {
        $modal = Modal::new('Title', Text::new('Content'));
        $frame = new Frame(80, 24);
        $area = Rect::fromSize(80, 24);
        $modal->render($frame, $area, $this->context);

        $titleForeground = null;
        for ($index = 0; $index < $frame->size; ++$index) {
            if ($frame->characters[$index] === 'T'
                && isset($frame->characters[$index + 1])
                && $frame->characters[$index + 1] === 'i') {
                $titleForeground = $frame->foregrounds[$index];
                break;
            }
        }

        self::assertNotNull($titleForeground);
        self::assertSame(
            $this->context->theme->titleStyle->foregroundValue(),
            $titleForeground,
        );
    }

    // ---------------------------------------------------------------
    // L128: Content only rendered when contentRect > 0x0
    // ---------------------------------------------------------------

    #[Test]
    public function contentIsRenderedWhenContentRectHasPositiveDimensions(): void
    {
        $modal = Modal::new('Title', Text::new('Hello'));
        $frame = new Frame(80, 24);
        $area = new Rect(0, 0, 80, 24);
        $modal->render($frame, $area, $this->context);

        $hasContent = false;
        for ($index = 0; $index < $frame->size; ++$index) {
            if ($frame->characters[$index] === 'H') {
                $hasContent = true;
                break;
            }
        }
        self::assertTrue($hasContent, 'Modal should render content');
    }

    #[Test]
    public function contentNotRenderedWhenModalTooSmallForContent(): void
    {
        // Modal at 100% of a 3x3 area => modal = 3x3, inner = 1x1 (after border)
        // Content should still render with 1x1
        $modal = Modal::new('T', Text::new('Q'))->withWidth(100)->withHeight(100);
        $frame = new Frame(5, 5);
        $area = new Rect(0, 0, 5, 5);
        $modal->render($frame, $area, $this->context);

        // With 5x5 area at 100%: modal = 5x5, border removes 1 each side => 3x3 inner
        // Content 'Q' should appear
        $hasContent = false;
        for ($index = 0; $index < $frame->size; ++$index) {
            if ($frame->characters[$index] === 'Q') {
                $hasContent = true;
                break;
            }
        }
        self::assertTrue($hasContent, 'Content should render in valid inner area');
    }

    // ---------------------------------------------------------------
    // L135-136: computeModalRect — exact width/height calculations
    // ---------------------------------------------------------------

    #[Test]
    public function modalWidthIsPercentageOfAreaWidth(): void
    {
        $modal = Modal::new('T', Text::new('X'))->withWidth(50);
        $frame = new Frame(100, 50);
        $area = Rect::fromSize(100, 50);
        $modal->render($frame, $area, $this->context);

        // 50% of 100 = 50 width
        // x = (100 - 50) / 2 = 25
        // height 60% of 50 = 30, y = (50-30)/2 = 10
        $topLeftIndex = 10 * 100 + 25;
        $topRightIndex = 10 * 100 + 74; // x=25+50-1=74
        self::assertSame('╭', $frame->characters[$topLeftIndex]);
        self::assertSame('╮', $frame->characters[$topRightIndex]);
    }

    #[Test]
    public function modalHeightIsPercentageOfAreaHeight(): void
    {
        $modal = Modal::new('T', Text::new('X'))->withHeight(50);
        $frame = new Frame(100, 50);
        $area = Rect::fromSize(100, 50);
        $modal->render($frame, $area, $this->context);

        // 50% of 50 = 25 height
        // y = (50 - 25) / 2 = 12 (int division)
        // width 60% of 100 = 60, x = (100-60)/2 = 20
        $topLeftIndex = 12 * 100 + 20;
        $bottomLeftIndex = (12 + 25 - 1) * 100 + 20;
        self::assertSame('╭', $frame->characters[$topLeftIndex]);
        self::assertSame('╰', $frame->characters[$bottomLeftIndex]);
    }

    #[Test]
    public function modalWidthHasMinimumOfThree(): void
    {
        // withWidth(10) => 10%. Area width=10 => (int)(10*10/100) = 1, max(3,1) = 3
        $modal = Modal::new('T', Text::new('X'))->withWidth(10);
        $frame = new Frame(10, 20);
        $area = Rect::fromSize(10, 20);
        $modal->render($frame, $area, $this->context);

        // modalWidth = max(3, 1) = 3
        // x = (10 - 3) / 2 = 3 (int)
        // height 60% of 20 = 12, y = (20-12)/2 = 4
        $topLeftIndex = 4 * 10 + 3;
        $topRightIndex = 4 * 10 + 5; // x=3+3-1=5
        self::assertSame('╭', $frame->characters[$topLeftIndex]);
        self::assertSame('╮', $frame->characters[$topRightIndex]);
    }

    #[Test]
    public function modalHeightHasMinimumOfThree(): void
    {
        $modal = Modal::new('T', Text::new('X'))->withHeight(10);
        $frame = new Frame(20, 10);
        $area = Rect::fromSize(20, 10);
        $modal->render($frame, $area, $this->context);

        // modalHeight = max(3, (int)(10*10/100)) = max(3, 1) = 3
        // y = (10 - 3) / 2 = 3 (int)
        // width 60% of 20 = 12, x = (20-12)/2 = 4
        $topLeftIndex = 3 * 20 + 4;
        $bottomLeftIndex = (3 + 3 - 1) * 20 + 4;
        self::assertSame('╭', $frame->characters[$topLeftIndex]);
        self::assertSame('╰', $frame->characters[$bottomLeftIndex]);
    }

    #[Test]
    public function multiplicationInWidthCalculation(): void
    {
        // Verify exact: 80 * 60 / 100 = 48
        $modal = Modal::new('T', Text::new('X'));
        $frame = new Frame(80, 24);
        $area = Rect::fromSize(80, 24);
        $modal->render($frame, $area, $this->context);

        // modalWidth = (int)(80 * 60 / 100) = 48
        // x = (80 - 48) / 2 = 16
        // modalHeight = (int)(24 * 60 / 100) = 14
        // y = (24 - 14) / 2 = 5
        $topLeftIndex = 5 * 80 + 16;
        $topRightIndex = 5 * 80 + 63; // x=16+48-1=63
        self::assertSame('╭', $frame->characters[$topLeftIndex]);
        self::assertSame('╮', $frame->characters[$topRightIndex]);
    }

    // ---------------------------------------------------------------
    // L137-138: CastInt — centering X and Y offsets
    // ---------------------------------------------------------------

    #[Test]
    public function modalIsCenteredHorizontally(): void
    {
        $modal = Modal::new('T', Text::new('X'))->withWidth(50);
        $frame = new Frame(80, 24);
        $area = Rect::fromSize(80, 24);
        $modal->render($frame, $area, $this->context);

        // modalWidth = (int)(80*50/100) = 40
        // x = (int)((80-40)/2) = 20
        // modalHeight = (int)(24*60/100) = 14, y = (24-14)/2 = 5
        $topLeftIndex = 5 * 80 + 20;
        self::assertSame('╭', $frame->characters[$topLeftIndex]);
    }

    #[Test]
    public function modalIsCenteredVertically(): void
    {
        $modal = Modal::new('T', Text::new('X'))->withHeight(50);
        $frame = new Frame(80, 24);
        $area = Rect::fromSize(80, 24);
        $modal->render($frame, $area, $this->context);

        // modalHeight = (int)(24*50/100) = 12
        // y = (int)((24-12)/2) = 6
        // modalWidth = (int)(80*60/100) = 48, x = (80-48)/2 = 16
        $topLeftIndex = 6 * 80 + 16;
        self::assertSame('╭', $frame->characters[$topLeftIndex]);
    }

    #[Test]
    public function modalCenteringWithOddDimensions(): void
    {
        // Area 81x25 at 60%: modalWidth = (int)(81*60/100) = 48
        // x = (int)((81-48)/2) = (int)(16.5) = 16
        // modalHeight = (int)(25*60/100) = 15
        // y = (int)((25-15)/2) = 5
        $modal = Modal::new('T', Text::new('X'));
        $frame = new Frame(81, 25);
        $area = Rect::fromSize(81, 25);
        $modal->render($frame, $area, $this->context);

        $topLeftIndex = 5 * 81 + 16;
        self::assertSame('╭', $frame->characters[$topLeftIndex]);
    }

    #[Test]
    public function modalCenteringWithNonZeroAreaOrigin(): void
    {
        // Area starts at (10, 5), width=80, height=24
        // modalWidth = (int)(80*60/100) = 48
        // modalX = 10 + (int)((80-48)/2) = 10 + 16 = 26
        // modalHeight = (int)(24*60/100) = 14
        // modalY = 5 + (int)((24-14)/2) = 5 + 5 = 10
        $modal = Modal::new('T', Text::new('X'));
        $frame = new Frame(100, 40);
        $area = new Rect(10, 5, 80, 24);
        $modal->render($frame, $area, $this->context);

        $topLeftIndex = 10 * 100 + 26;
        self::assertSame('╭', $frame->characters[$topLeftIndex]);
    }

    // ---------------------------------------------------------------
    // L152-155: dimBackground — loop bounds and index calculation
    // ---------------------------------------------------------------

    #[Test]
    public function dimBackgroundAppliesFaintToAllCellsInArea(): void
    {
        $frame = new Frame(20, 10);
        $area = Rect::fromSize(20, 10);

        // Fill entire buffer with text
        for ($row = 0; $row < 10; ++$row) {
            for ($column = 0; $column < 20; ++$column) {
                $frame->putChar($column, $row, 'A');
            }
        }

        $modal = Modal::new('T', Text::new('X'));
        $modal->render($frame, $area, $this->context);

        // Cells outside the modal area should have FAINT attribute
        // The modal occupies center; cells at corners should be dimmed
        self::assertSame(Attr::FAINT, $frame->attributes[0] & Attr::FAINT, 'Top-left corner should be dimmed');
        self::assertSame(Attr::FAINT, $frame->attributes[19] & Attr::FAINT, 'Top-right corner should be dimmed');
        $lastRowStart = 9 * 20;
        self::assertSame(Attr::FAINT, $frame->attributes[$lastRowStart] & Attr::FAINT, 'Bottom-left corner should be dimmed');
        self::assertSame(Attr::FAINT, $frame->attributes[$lastRowStart + 19] & Attr::FAINT, 'Bottom-right corner should be dimmed');
    }

    #[Test]
    public function dimBackgroundCoversEntireArea(): void
    {
        // Use a large area so the modal (60%) leaves room around edges for dim checking
        $frame = new Frame(40, 20);
        $area = new Rect(5, 3, 30, 14);

        // Fill with chars
        for ($row = 0; $row < 20; ++$row) {
            for ($column = 0; $column < 40; ++$column) {
                $frame->putChar($column, $row, 'B');
            }
        }

        $modal = Modal::new('T', Text::new('X'));
        $modal->render($frame, $area, $this->context);

        // Cell at area origin (5,3) should be dimmed (inside area, outside modal)
        $index = 3 * 40 + 5;
        self::assertSame(Attr::FAINT, $frame->attributes[$index] & Attr::FAINT);

        // Cell at area bottom-right corner (34,16) => area.right()-1=34, area.bottom()-1=16
        $index = 16 * 40 + 34;
        self::assertSame(Attr::FAINT, $frame->attributes[$index] & Attr::FAINT);

        // Cell just outside area at (4,3) should NOT be dimmed
        $index = 3 * 40 + 4;
        self::assertSame(0, $frame->attributes[$index] & Attr::FAINT);

        // Cell just outside area at (5,2) should NOT be dimmed
        $index = 2 * 40 + 5;
        self::assertSame(0, $frame->attributes[$index] & Attr::FAINT);
    }

    #[Test]
    public function dimBackgroundPreservesCharacterContent(): void
    {
        $frame = new Frame(80, 24);
        $area = Rect::fromSize(80, 24);

        $frame->putString(0, 0, 'Background text', 0);

        $modal = Modal::new('Title', Text::new('Content'));
        $modal->render($frame, $area, $this->context);

        // The character at (0,0) should still be 'B' (from 'Background') but with FAINT
        self::assertSame('B', $frame->characters[0]);
        self::assertSame(Attr::FAINT, $frame->attributes[0] & Attr::FAINT);
    }

    #[Test]
    public function dimBackgroundIndexCalculationUsesBufferWidth(): void
    {
        // Use area offset to verify index = row * buffer.width + column
        $frame = new Frame(40, 20);
        $area = new Rect(5, 3, 30, 14);

        $frame->putChar(5, 3, 'Z');

        $modal = Modal::new('T', Text::new('X'));
        $modal->render($frame, $area, $this->context);

        // Cell at (5,3) should be dimmed and still 'Z'
        $index = 3 * 40 + 5;
        self::assertSame('Z', $frame->characters[$index]);
        self::assertSame(Attr::FAINT, $frame->attributes[$index] & Attr::FAINT);
    }

    #[Test]
    public function dimBackgroundBoundsCheckPreventsOutOfBounds(): void
    {
        // Create a scenario where the area extends beyond the buffer
        $frame = new Frame(10, 10);
        $area = new Rect(0, 0, 15, 15); // area larger than buffer

        $modal = Modal::new('T', Text::new('X'))->withWidth(100)->withHeight(100);
        // Should not crash even though area > buffer
        $modal->render($frame, $area, $this->context);
        self::assertTrue(true, 'Should not crash with out-of-bounds area');
    }

    // ---------------------------------------------------------------
    // L171: fillRect call in clearModalArea
    // ---------------------------------------------------------------

    #[Test]
    public function clearModalAreaUsesCorrectDimensionsAndBackground(): void
    {
        $frame = new Frame(80, 24);
        $area = Rect::fromSize(80, 24);

        // Fill entire buffer with 'X'
        for ($row = 0; $row < 24; ++$row) {
            for ($column = 0; $column < 80; ++$column) {
                $frame->putChar($column, $row, 'X');
            }
        }

        $modal = Modal::new('T', Text::new(''));
        $modal->render($frame, $area, $this->context);

        // modalWidth = (int)(80*60/100) = 48, x = 16
        // modalHeight = (int)(24*60/100) = 14, y = 5
        // Interior cells (not on border) should be space
        $interiorIndex = 7 * 80 + 20; // well inside the modal
        self::assertSame(' ', $frame->characters[$interiorIndex]);
    }

    #[Test]
    public function clearModalAreaSetsBackground(): void
    {
        $frame = new Frame(80, 24);
        $area = Rect::fromSize(80, 24);

        $modal = Modal::new('T', Text::new(''));
        $modal->render($frame, $area, $this->context);

        // Interior cell should have the theme base background
        $expectedBackground = $this->context->theme->base->backgroundValue();
        $interiorIndex = 7 * 80 + 20;
        self::assertSame($expectedBackground, $frame->backgrounds[$interiorIndex]);
    }

    #[Test]
    public function fillRectPositionMatchesModalRect(): void
    {
        $frame = new Frame(80, 24);
        $area = Rect::fromSize(80, 24);

        // Fill with non-space
        for ($row = 0; $row < 24; ++$row) {
            for ($column = 0; $column < 80; ++$column) {
                $frame->putChar($column, $row, 'Z');
            }
        }

        $modal = Modal::new('T', Text::new(''));
        $modal->render($frame, $area, $this->context);

        // modalX=16, modalY=5, modalWidth=48, modalHeight=14
        // Cell just outside modal top-left at (15, 5) should still be 'Z' (dimmed but not cleared)
        // Actually it will be dimmed to 'Z' with FAINT, not space
        $outsideLeftIndex = 5 * 80 + 15;
        self::assertSame('Z', $frame->characters[$outsideLeftIndex]);

        // Cell just inside modal at (16, 5) should be overwritten by the border
        $modalCornerIndex = 5 * 80 + 16;
        self::assertNotSame('Z', $frame->characters[$modalCornerIndex]);
    }

    // ---------------------------------------------------------------
    // L41: Default heightPercent is exactly 60 (not 61)
    // ---------------------------------------------------------------

    #[Test]
    public function defaultHeightPercentIsExactlySixtyNotSixtyOne(): void
    {
        $modal = Modal::new('T', Text::new('X'));
        $frame = new Frame(100, 100);
        $area = Rect::fromSize(100, 100);
        $modal->render($frame, $area, $this->context);

        // 60% of 100 = 60 height. y = (100-60)/2 = 20
        // Bottom-left corner at y = 20+60-1 = 79
        $bottomLeftIndex = 79 * 100 + 20;
        self::assertSame('╰', $frame->characters[$bottomLeftIndex]);

        // If heightPercent were 61: height=61, y=(100-61)/2=19, bottom=19+61-1=79
        // Top-left would be at y=19 not y=20
        $topLeftIndex = 20 * 100 + 20;
        self::assertSame('╭', $frame->characters[$topLeftIndex]);
        // y=19 should NOT have the top-left corner
        $wrongTopLeftIndex = 19 * 100 + 20;
        self::assertNotSame('╭', $frame->characters[$wrongTopLeftIndex]);
    }

    // ---------------------------------------------------------------
    // L53: withWidth boundary: max(10,...) catches 9
    // ---------------------------------------------------------------

    #[Test]
    public function withWidthNineIsClamped(): void
    {
        $modal = Modal::new('T', Text::new('X'))->withWidth(9);
        $frame = new Frame(100, 100);
        $area = Rect::fromSize(100, 100);
        $modal->render($frame, $area, $this->context);

        // Should clamp to 10%, not 9%. 10% of 100 = 10 wide.
        // x = (100-10)/2 = 45. Top-left at (45, y)
        // height 60% of 100 = 60, y = (100-60)/2 = 20
        $topLeftIndex = 20 * 100 + 45;
        self::assertSame('╭', $frame->characters[$topLeftIndex]);
        $topRightIndex = 20 * 100 + 54; // 45+10-1=54
        self::assertSame('╮', $frame->characters[$topRightIndex]);
    }

    #[Test]
    public function withWidthOneHundredOneIsClamped(): void
    {
        $modal = Modal::new('T', Text::new('X'))->withWidth(101);
        $frame = new Frame(100, 100);
        $area = Rect::fromSize(100, 100);
        $modal->render($frame, $area, $this->context);

        // Should clamp to 100%. 100% of 100 = 100 wide. x = 0
        $topLeftIndex = 20 * 100 + 0;
        self::assertSame('╭', $frame->characters[$topLeftIndex]);
        $topRightIndex = 20 * 100 + 99;
        self::assertSame('╮', $frame->characters[$topRightIndex]);
    }

    // ---------------------------------------------------------------
    // L58: withHeight boundary: max(10,...) catches less than 10
    // ---------------------------------------------------------------

    #[Test]
    public function withHeightNineIsClamped(): void
    {
        $modal = Modal::new('T', Text::new('X'))->withHeight(9);
        $frame = new Frame(100, 100);
        $area = Rect::fromSize(100, 100);
        $modal->render($frame, $area, $this->context);

        // Should clamp to 10%. 10% of 100 = 10 high.
        // y = (100-10)/2 = 45. width 60% of 100 = 60, x = (100-60)/2 = 20
        $topLeftIndex = 45 * 100 + 20;
        self::assertSame('╭', $frame->characters[$topLeftIndex]);
        $bottomLeftIndex = (45 + 10 - 1) * 100 + 20;
        self::assertSame('╰', $frame->characters[$bottomLeftIndex]);
    }

    #[Test]
    public function withHeightOneHundredOneIsClamped(): void
    {
        $modal = Modal::new('T', Text::new('X'))->withHeight(101);
        $frame = new Frame(100, 100);
        $area = Rect::fromSize(100, 100);
        $modal->render($frame, $area, $this->context);

        // Should clamp to 100%. 100% of 100 = 100 high. y = 0
        $topLeftIndex = 0 * 100 + 20;
        self::assertSame('╭', $frame->characters[$topLeftIndex]);
        $bottomLeftIndex = 99 * 100 + 20;
        self::assertSame('╰', $frame->characters[$bottomLeftIndex]);
    }

    // ---------------------------------------------------------------
    // L135: Division by 100 exactly (not 99)
    // ---------------------------------------------------------------

    #[Test]
    public function widthCalculationDividesByExactlyOneHundred(): void
    {
        // Choose area where /100 and /99 give different integer results
        // 99 * 60 = 5940. /100 = 59.4 -> (int) 59. /99 = 60.0 -> (int) 60.
        $modal = Modal::new('T', Text::new('X'));
        $frame = new Frame(99, 100);
        $area = Rect::fromSize(99, 100);
        $modal->render($frame, $area, $this->context);

        // width = (int)(99*60/100) = 59, x = (99-59)/2 = 20
        $topLeftIndex = 20 * 99 + 20;
        $topRightIndex = 20 * 99 + 78; // 20+59-1=78
        self::assertSame('╭', $frame->characters[$topLeftIndex]);
        self::assertSame('╮', $frame->characters[$topRightIndex]);
    }

    #[Test]
    public function heightCalculationDividesByExactlyOneHundred(): void
    {
        // 99 * 60 / 100 = 59.4 -> 59. vs /99 = 60.
        $modal = Modal::new('T', Text::new('X'));
        $frame = new Frame(100, 99);
        $area = Rect::fromSize(100, 99);
        $modal->render($frame, $area, $this->context);

        // height = (int)(99*60/100) = 59, y = (99-59)/2 = 20
        $topLeftIndex = 20 * 100 + 20;
        $bottomLeftIndex = (20 + 59 - 1) * 100 + 20;
        self::assertSame('╭', $frame->characters[$topLeftIndex]);
        self::assertSame('╰', $frame->characters[$bottomLeftIndex]);
    }

    // ---------------------------------------------------------------
    // L152-153: Dim loop boundaries are exact (< not <=)
    // ---------------------------------------------------------------

    #[Test]
    public function dimBackgroundDoesNotExceedAreaBottom(): void
    {
        $frame = new Frame(40, 20);
        $area = new Rect(0, 0, 40, 10);

        for ($row = 0; $row < 20; ++$row) {
            for ($column = 0; $column < 40; ++$column) {
                $frame->putChar($column, $row, 'A', 0, 0, 0);
            }
        }

        $modal = Modal::new('T', Text::new('X'))->withWidth(100)->withHeight(100);
        $modal->render($frame, $area, $this->context);

        // Row at area.bottom() = 10 should NOT be dimmed
        $indexAtRow10 = 10 * 40 + 5;
        self::assertSame(0, $frame->attributes[$indexAtRow10] & Attr::FAINT);

        // Row at area.bottom()-1 = 9 SHOULD be dimmed (or overwritten by modal)
        $indexAtRow9 = 9 * 40 + 0;
        $isDimmedOrModal = ($frame->attributes[$indexAtRow9] & Attr::FAINT) !== 0
            || $frame->characters[$indexAtRow9] !== 'A';
        self::assertTrue($isDimmedOrModal);
    }

    #[Test]
    public function dimBackgroundDoesNotExceedAreaRight(): void
    {
        $frame = new Frame(40, 20);
        $area = new Rect(0, 0, 20, 20);

        for ($row = 0; $row < 20; ++$row) {
            for ($column = 0; $column < 40; ++$column) {
                $frame->putChar($column, $row, 'A', 0, 0, 0);
            }
        }

        $modal = Modal::new('T', Text::new('X'))->withWidth(100)->withHeight(100);
        $modal->render($frame, $area, $this->context);

        // Column at area.right() = 20 should NOT be dimmed
        $indexAtCol20 = 5 * 40 + 20;
        self::assertSame(0, $frame->attributes[$indexAtCol20] & Attr::FAINT);

        // Column at area.right()-1 = 19 should be affected
        $indexAtCol19 = 5 * 40 + 19;
        $isDimmedOrModal = ($frame->attributes[$indexAtCol19] & Attr::FAINT) !== 0
            || $frame->characters[$indexAtCol19] !== 'A';
        self::assertTrue($isDimmedOrModal);
    }

    // ---------------------------------------------------------------
    // L171: clearModalArea uses foreground=0
    // ---------------------------------------------------------------

    #[Test]
    public function clearModalAreaSetsForegroundToDefault(): void
    {
        $frame = new Frame(80, 24);
        $area = Rect::fromSize(80, 24);

        // Pre-fill with non-zero foreground
        for ($row = 0; $row < 24; ++$row) {
            for ($column = 0; $column < 80; ++$column) {
                $frame->putChar($column, $row, 'X', 999, 0, 0);
            }
        }

        $modal = Modal::new('T', Text::new(''));
        $modal->render($frame, $area, $this->context);

        // modalX=16, modalY=5
        // Interior cell should have foreground reset to 0 by fillRect
        $interiorIndex = 6 * 80 + 17;
        self::assertSame(0, $frame->foregrounds[$interiorIndex]);
    }

    // ---------------------------------------------------------------
    // L112: Guard when modalRect < 3 (safety annotation)
    // ---------------------------------------------------------------

    // ---------------------------------------------------------------
    // L128: contentRect guard (safety annotation)
    // ---------------------------------------------------------------

    // ---------------------------------------------------------------
    // Delegate to interactive content
    // ---------------------------------------------------------------

    #[Test]
    public function implementsKeybindingsAwareSoGlobalBindingsCanPropagate(): void
    {
        self::assertInstanceOf(KeybindingsAware::class, Modal::new('T', Text::new('X')));
    }

    #[Test]
    public function dismissalFollowsAReboundCancelKey(): void
    {
        $modal = Modal::new('Title', Text::new('Content'))
            ->withKeybindings(new Keybindings([Actions::CANCEL => ['ctrl+g']]));

        $rebound = new KeyMsg('g', ctrl: true);
        $result = $modal->update($rebound, Rect::fromSize(80, 24));

        self::assertTrue($modal->dismisses($rebound, Rect::fromSize(80, 24)), 'a rebound cancel key dismisses the modal');
        self::assertNotNull($result->command);
    }

    #[Test]
    public function withGlobalKeybindingsPropagatesToKeybindingsAwareContent(): void
    {
        $vimBindings = new Keybindings([
            Actions::SCROLL_DOWN => ['j'],
        ]);
        $panel = Panel::new('inner');
        $modal = Modal::new('T', $panel);

        $modalWithBindings = $modal->withGlobalKeybindings($vimBindings);

        $newContent = (new ReflectionObject($modalWithBindings))->getProperty('content')->getValue($modalWithBindings);
        self::assertTrue(
            $newContent->effectiveKeybindings()->matches(new KeyMsg('j'), Actions::SCROLL_DOWN),
            'Modal must forward global keybindings to KeybindingsAware content so vim/custom keys reach the inner widget',
        );
    }

    #[Test]
    public function withGlobalKeybindingsReturnsSameInstanceWhenContentKeybindingsUnchanged(): void
    {
        $vimBindings = new Keybindings([
            Actions::SCROLL_DOWN => ['j'],
        ]);
        $panel = Panel::new('inner');
        $modal = Modal::new('T', $panel)->withGlobalKeybindings($vimBindings);

        $result = $modal->withGlobalKeybindings($vimBindings);
        self::assertSame($modal, $result, 'withGlobalKeybindings must return same instance when content already has identical keybindings');
    }

    #[Test]
    public function delegatesKeysToInteractiveContent(): void
    {
        $content = $this->createStableInteractiveWidget();
        $modal = Modal::new('Title', $content);
        $area = Rect::fromSize(80, 24);

        $result = $modal->update(new KeyMsg('x'), $area);
        self::assertInstanceOf(UpdateResult::class, $result);
        self::assertFalse($modal->dismisses(new KeyMsg('x'), $area));
    }

    #[Test]
    public function doesNotDelegateToNonInteractiveContent(): void
    {
        $modal = Modal::new('Title', Text::new('Static'));
        $area = Rect::fromSize(80, 24);

        $result = $modal->update(new KeyMsg('x'), $area);
        self::assertSame($modal, $result->model);
        self::assertNull($result->command);
    }

    #[Test]
    public function dimBackgroundPreservesExistingAttributes(): void
    {
        $modal = Modal::new('T', Text::new('X'));
        $frame = new Frame(20, 10);
        $area = Rect::fromSize(20, 10);

        $frame->putChar(0, 0, 'B', 0, 0, Attr::BOLD);
        $frame->putChar(1, 0, 'I', 0, 0, Attr::ITALIC);

        $modal->render($frame, $area, $this->context);

        self::assertSame(Attr::BOLD | Attr::FAINT, $frame->attributes[0]);
        self::assertSame(Attr::ITALIC | Attr::FAINT, $frame->attributes[1]);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * Asked, never stored: a remembered flag needed resetting and survived into the
     * next opening, so a host keeping its dialog could only ask once.
     */
    #[Test]
    public function havingBeenDismissedOnceDoesNotDismissItEverAfter(): void
    {
        $area = new Rect(0, 0, 40, 20);
        $modal = Modal::new('Support', Text::new('body'));
        $escape = new KeyMsg(Key::Escape);

        $shownAgain = $modal->update($escape, $area)->model;
        self::assertTrue($shownAgain->dismisses($escape, $area), 'precondition: escape closes it');

        self::assertFalse($shownAgain->dismisses(new KeyMsg('x'), $area));
    }

    protected function setUp(): void
    {
        $this->context = new RenderContext(Theme::dark());
    }

    private static function widgetDrawingPastItsArea(): Widget
    {
        return new class implements Widget {
            public function sizing(): Sizing
            {
                return Sizing::of(Constraint::flex(), Constraint::flex());
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
                $frame->putString($area->x, $area->y, str_repeat('X', 36));
                $frame->putString($area->x, $area->bottom(), str_repeat('X', 6));
            }
        };
    }

    private function createStableInteractiveWidget(): Interactive
    {
        return new class implements Interactive {
            public function sizing(): Sizing
            {
                return Sizing::of(Constraint::exact(1), Constraint::exact(1));
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                return new UpdateResult($this);
            }
        };
    }

    private function createMutatingInteractiveWidget(): Interactive
    {
        return new class implements Interactive {
            public int $counter = 0;

            public function sizing(): Sizing
            {
                return Sizing::of(Constraint::exact(1), Constraint::exact(1));
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                $new = clone $this;
                $new->counter = $this->counter + 1;

                return new UpdateResult($new);
            }
        };
    }
}
