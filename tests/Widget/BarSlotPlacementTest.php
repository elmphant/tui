<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Cmd\MessageCmd;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseButton;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Tests\Support\TestMessage;
use Elmphant\Tui\Widget\Alignment;
use Elmphant\Tui\Widget\Bar\Footer;
use Elmphant\Tui\Widget\Bar\StatusBar;
use Elmphant\Tui\Widget\Feedback\ProgressBar;
use Elmphant\Tui\Widget\Field\Button;
use Elmphant\Tui\Widget\Interactive;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Structure\Container;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BarSlotPlacementTest extends TestCase
{
    private const BAR_WIDTH = 24;

    private RenderContext $context;

    #[Test]
    public function aLeftSlotStartsAtTheContentOriginAndKeepsTheBarBackground(): void
    {
        $frame = $this->paint(Footer::new()->withLeft(Text::new('Hi')));

        self::assertSame('Hi', $this->row($frame));
        self::assertSame(
            $this->context->theme->footerStyle->backgroundValue(),
            $frame->backgrounds[0],
            'A slot paints over the bar, so its cells carry the bar background',
        );
    }

    #[Test]
    public function aRightSlotEndsAtTheRightEdge(): void
    {
        $frame = $this->paint(Footer::new()->withRight(Text::new('Hi')));

        self::assertSame(str_repeat(' ', 22) . 'Hi', $this->padded($frame));
    }

    #[Test]
    public function aCentreSlotSitsInTheMiddleOfTheRow(): void
    {
        $frame = $this->paint(Footer::new()->withCenter(Text::new('Hi')));

        self::assertSame(str_repeat(' ', 11) . 'Hi', $this->row($frame));
    }

    #[Test]
    public function aFullRowPainterIsPlacedByItsInkAlone(): void
    {
        $frame = $this->paint(Footer::new()->withRight(StatusBar::forHints(['q' => 'quit'])));

        self::assertSame(str_repeat(' ', 17) . ' q quit', $this->padded($frame));
    }

    #[Test]
    public function aSlotThatPaintsNothingLeavesTheBarBlank(): void
    {
        $silent = new class implements Widget {
            public function sizing(): Sizing
            {
                return Sizing::of(Constraint::exact(4), Constraint::exact(1));
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
            }
        };

        $frame = $this->paint(Footer::new()->withCenter($silent));

        self::assertSame('', $this->row($frame));
    }

    #[Test]
    public function aSlotDrawsInTheWidthItDeclares(): void
    {
        $greedy = new class implements Widget {
            public function sizing(): Sizing
            {
                return Sizing::of(Constraint::exact(4), Constraint::exact(1));
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
                $frame->fillRect($area->x, $area->y, $area->width, 1, 0, 0, 0, 'X');
            }
        };

        $frame = $this->paint(Footer::new()->withCenter($greedy));

        self::assertSame(str_repeat(' ', 10) . 'XXXX', $this->row($frame));
    }

    #[Test]
    public function aRightSlotKeepsTheTrailingSpacesItAsksFor(): void
    {
        $frame = $this->paint(Footer::new()->withRight(Text::new('Hi  ')));

        self::assertSame(str_repeat(' ', 20) . 'Hi  ', $this->padded($frame));
    }

    #[Test]
    public function aWidgetThatOnlyFillsWhatItIsGivenDrawsNothingInASlot(): void
    {
        $frame = $this->paint(Footer::new()->withRight(ProgressBar::new(0.5)));

        self::assertSame('', $this->row($frame));
    }

    #[Test]
    public function aFillOnlyWidgetDrawsInTheWidthItsContainerDeclares(): void
    {
        $sized = Container::around(ProgressBar::new(0.5))
            ->withSizing(Sizing::of(Constraint::exact(10), Constraint::exact(1)));

        $frame = $this->paint(Footer::new()->withRight($sized));

        self::assertSame(str_repeat(' ', 14) . '█████─ 50%', $this->padded($frame), 'Ten columns, right up against the edge');
    }

    #[Test]
    public function aClickOverTheRightSlotLeavesTheLeftOneAlone(): void
    {
        $rightPressed = new TestMessage('right');
        $bar = Footer::new()
            ->withLeft(Button::new('L', new TestMessage('left')))
            ->withRight(Button::new('R', $rightPressed));
        $area = new Rect(0, 0, self::BAR_WIDTH, 1);

        $armed = $bar->update(new MouseMsg(22, 0, MouseAction::Press, MouseButton::Left), $area)->model;
        $result = $armed->update(new MouseMsg(22, 0, MouseAction::Release, MouseButton::Left), $area);

        $command = $result->command;
        self::assertInstanceOf(MessageCmd::class, $command);
        self::assertSame($rightPressed, $command->message);
    }

    #[Test]
    public function aMarkedSlotWithNoWidthStillTakesKeys(): void
    {
        $pressed = new TestMessage('pressed');
        $widthless = new class($pressed) implements Interactive {
            public function __construct(private readonly Message $pressed)
            {
            }

            public function sizing(): Sizing
            {
                return Sizing::of(Constraint::exact(0), Constraint::exact(1));
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                return new UpdateResult($this, $message instanceof KeyMsg ? Cmd::message($this->pressed) : null);
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
            }
        };

        $result = Footer::new()->withLeft($widthless)->withMarkedSlot(Alignment::Left)->update(new KeyMsg(Key::Enter), new Rect(0, 0, self::BAR_WIDTH, 1));

        $command = $result->command;
        self::assertInstanceOf(MessageCmd::class, $command);
        self::assertSame($pressed, $command->message);
    }

    protected function setUp(): void
    {
        $this->context = new RenderContext(Theme::dark());
    }

    private function paint(Widget $bar): Frame
    {
        $frame = new Frame(self::BAR_WIDTH, 1);
        $bar->render($frame, new Rect(0, 0, self::BAR_WIDTH, 1), $this->context);

        return $frame;
    }

    private function row(Frame $frame): string
    {
        return rtrim($this->padded($frame));
    }

    private function padded(Frame $frame): string
    {
        $row = '';
        for ($x = 0; $x < self::BAR_WIDTH; ++$x) {
            $row .= $frame->characters[$x] === '' ? ' ' : $frame->characters[$x];
        }

        return $row;
    }
}
