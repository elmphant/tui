<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Testing;

use Elmphant\Tui\Core\App;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\Model;
use Elmphant\Tui\Core\Reducer;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\FloatMouseMsg;
use Elmphant\Tui\Message\FocusMsg;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseButton;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Message\ResizeMsg;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Tests\Support\FloatingModel;
use Elmphant\Tui\Tests\Support\TestMessage;
use Elmphant\Tui\Tests\Support\TestPopup;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Widget;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ModelHarnessTest extends TestCase
{
    #[Test]
    public function dispatchesMessagesAndExposesTheTypedCurrentModel(): void
    {
        $harness = ModelHarness::for(new HarnessCounter());

        self::assertSame($harness, $harness->dispatch(new KeyMsg('+')));
        self::assertInstanceOf(HarnessCounter::class, $harness->model());
        self::assertSame(1, $harness->model()->count);
        self::assertNull($harness->lastCommand());
    }

    #[Test]
    public function usesConventionalTerminalDimensionsByDefault(): void
    {
        $frame = ModelHarness::for(new HarnessCounter())->renderFrame();

        self::assertSame(80, $frame->width);
        self::assertSame(24, $frame->height);
    }

    #[Test]
    public function rendersWithTheSuppliedTheme(): void
    {
        $theme = Theme::light();
        $frame = ModelHarness::for(new ThemeEchoModel(), theme: $theme)->renderFrame();

        self::assertSame($theme->accent->foreground?->value, $frame->foreground(0));
    }

    #[Test]
    public function capturesInitialAndUpdateCommandsWithoutExecutingEffects(): void
    {
        $harness = ModelHarness::for(new HarnessCounter());

        self::assertSame($harness, $harness->initialize());
        self::assertNotNull($harness->lastCommand());

        $harness->dispatch(new KeyMsg('q'));
        self::assertNotNull($harness->lastCommand());
    }

    #[Test]
    public function aMessageInitSentReachesTheModel(): void
    {
        $model = ModelHarness::for(new HarnessRecorder(Cmd::message(new TestMessage('loaded'))))
            ->initialize()
            ->model();

        self::assertContains(TestMessage::class, $model->seen);
    }

    #[Test]
    public function theTerminalSizeArrivesBeforeWhatInitSent(): void
    {
        $model = ModelHarness::for(new HarnessRecorder(Cmd::message(new TestMessage('loaded'))))
            ->initialize()
            ->model();

        self::assertSame([ResizeMsg::class, TestMessage::class], $model->seen);
    }

    #[Test]
    public function whatAnArrivalAnswersWithIsCarriedOut(): void
    {
        $model = ModelHarness::for(new HarnessRecorder(
            Cmd::focus(ComponentId::named('field')),
            Cmd::message(new TestMessage('seated')),
        ))->initialize()->model();

        self::assertContains(FocusMsg::class, $model->seen);
        self::assertContains(TestMessage::class, $model->seen);
    }

    #[Test]
    public function aCellNothingDrewOnShowsTheThemeBackground(): void
    {
        $theme = Theme::solarizedDark();
        self::assertNotSame(0, $theme->base->backgroundValue());

        $frame = ModelHarness::for(new HarnessRecorder(), theme: $theme)->renderFrame();

        self::assertSame($theme->base->backgroundValue(), $frame->background(5));
    }

    #[Test]
    public function rendersDirectlyToAFrameAndReadableScreen(): void
    {
        $harness = ModelHarness::for(new HarnessCounter(), width: 20, height: 4)
            ->dispatch(new KeyMsg('+'));

        $frame = $harness->renderFrame();

        self::assertSame(20, $frame->width);
        self::assertSame(4, $frame->height);
        self::assertSame('Count: 1', $harness->renderScreen()->textAt(0, 0, 8));
    }

    #[Test]
    public function resizedAreaIsUsedByTheNextUpdateAndRender(): void
    {
        $model = new AreaRecordingModel();
        $harness = ModelHarness::for($model, 80, 24)
            ->resize(37, 11)
            ->dispatch(new KeyMsg('x'));

        self::assertSame(37, $harness->model()->lastWidth);
        self::assertSame(11, $harness->model()->lastHeight);
        self::assertSame('37x11', $harness->renderScreen()->textAt(0, 0, 5));
    }

    #[Test]
    public function resizingTellsTheModelRightAway(): void
    {
        $harness = ModelHarness::for(new AreaRecordingModel(), 80, 24)->resize(37, 11);

        self::assertSame(37, $harness->model()->lastWidth);
        self::assertSame(11, $harness->model()->lastHeight);
    }

    #[Test]
    public function rejectsNonTerminalDimensionsWithAnActionableMessage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ModelHarness dimensions must be positive, got 0x24');

        ModelHarness::for(new HarnessCounter(), width: 0, height: 24);
    }

    #[Test]
    public function resizeRejectsZeroHeight(): void
    {
        $harness = ModelHarness::for(new HarnessCounter());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ModelHarness dimensions must be positive, got 80x0');

        $harness->resize(80, 0);
    }

    #[Test]
    public function rejectsAReducerThatSwitchesOutOfTheModelContract(): void
    {
        $harness = ModelHarness::for(new class implements Model {
            public function init(): ?Cmd
            {
                return null;
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                return UpdateResult::changed(new class implements Reducer {
                    public function update(Message $message, Rect $area): UpdateResult
                    {
                        return UpdateResult::keep($this);
                    }
                });
            }

            public function render(Frame $frame, RenderContext $context): void
            {
            }
        });

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('ModelHarness update must return a Model');

        $harness->dispatch(new KeyMsg('x'));
    }

    #[Test]
    public function aClickOverAFloatDrawnByTheLastRenderIsWrappedForThatFloat(): void
    {
        $popup = new TestPopup(new Rect(5, 5, 10, 4));
        $model = new FloatingModel($popup);
        $harness = ModelHarness::for($model);

        $harness->renderFrame();

        self::assertCount(1, $harness->floatPlacements(), 'the popup must be drawn before the click can land on it');

        $harness->dispatch(new MouseMsg(6, 6, MouseAction::Press, MouseButton::Left));

        self::assertInstanceOf(FloatMouseMsg::class, $model->received);
        self::assertSame($popup, $model->received->popup());
    }

    #[Test]
    public function aClickBeforeTheFirstRenderIsPlain(): void
    {
        $model = new FloatingModel(new TestPopup(new Rect(5, 5, 10, 4)));
        $harness = ModelHarness::for($model);

        $harness->dispatch(new MouseMsg(6, 6, MouseAction::Press, MouseButton::Left));

        self::assertInstanceOf(MouseMsg::class, $model->received);
    }

    #[Test]
    public function aRenderThatThrowsLeavesTheFloatsOfTheFrameBeforeItRoutable(): void
    {
        $shown = new TestPopup(new Rect(0, 0, 4, 2), 'S');
        $model = new FloatingModel($shown);
        $harness = ModelHarness::for($model);

        $harness->renderFrame();

        $model->float = new TestPopup(new Rect(10, 10, 6, 3), 'N');
        $model->throwAfterRenderRoot = true;

        try {
            $harness->renderFrame();
            self::fail('renderFrame() should have propagated the render failure');
        } catch (RuntimeException) {
        }

        self::assertSame($shown, $harness->floatPlacements()[0]->float);
    }

    #[Test]
    public function everyRenderStartsAFreshFrameForRepaintRequests(): void
    {
        $model = new RepaintOnceModel();
        $harness = ModelHarness::for($model);

        $harness->renderFrame();
        $model->askForRepaint = false;
        $harness->renderFrame();

        self::assertSame(2, $model->renders, 'both frames rendered');
        self::assertNull($model->delaySeenByTheSecondRender, 'and the second frame started with no repaint request');
    }
}

final readonly class HarnessCounter extends App
{
    public function __construct(public int $count = 0)
    {
    }

    public function view(): Widget
    {
        return Text::new("Count: {$this->count}");
    }

    protected function setup(): ?Cmd
    {
        return Cmd::tick(1.0);
    }

    protected function onKey(KeyMsg $message, Rect $screen): UpdateResult
    {
        return match ($message->key) {
            '+' => UpdateResult::changed(new self($this->count + 1)),
            'q' => $this->withCmd(Cmd::quit()),
            default => UpdateResult::keep($this),
        };
    }
}

final readonly class AreaRecordingModel implements Model
{
    public function __construct(
        public int $lastWidth = 0,
        public int $lastHeight = 0,
    ) {
    }

    public function init(): ?Cmd
    {
        return null;
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return UpdateResult::changed(new self($area->width, $area->height));
    }

    public function render(Frame $frame, RenderContext $context): void
    {
        $frame->putString(0, 0, "{$frame->width}x{$frame->height}");
    }
}

final class RepaintOnceModel implements Model
{
    public bool $askForRepaint = true;
    public ?float $delaySeenByTheSecondRender = null;
    public int $renders = 0;

    public function init(): ?Cmd
    {
        return null;
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return UpdateResult::keep($this);
    }

    public function render(Frame $frame, RenderContext $context): void
    {
        ++$this->renders;

        if ($this->renders > 1) {
            $this->delaySeenByTheSecondRender = $context->repaintDelay();
        }

        if ($this->askForRepaint) {
            $context->requestRepaint(0.5);
        }
    }
}

final readonly class ThemeEchoModel implements Model
{
    public function init(): ?Cmd
    {
        return null;
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return UpdateResult::keep($this);
    }

    public function render(Frame $frame, RenderContext $context): void
    {
        $frame->putString(0, 0, 'x', $context->theme->accent->foreground?->value ?? 0);
    }
}

final class HarnessRecorder implements Model
{
    /**
     * @var list<class-string<Message>>
     */
    public array $seen = [];

    public function __construct(
        private readonly ?Cmd $initCommand = null,
        private readonly ?Cmd $arrivalAnswer = null,
    ) {
    }

    public function init(): ?Cmd
    {
        return $this->initCommand;
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        $this->seen[] = $message::class;

        return new UpdateResult($this, $message instanceof FocusMsg ? $this->arrivalAnswer : null);
    }

    public function render(Frame $frame, RenderContext $context): void
    {
    }
}
