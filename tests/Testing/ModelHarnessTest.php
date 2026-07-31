<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Testing;

use Elmphant\Tui\Core\App;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Model;
use Elmphant\Tui\Core\Reducer;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Text;
use Elmphant\Tui\Widget\Widget;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

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
    public function rendersDirectlyToAFrameAndReadableScreen(): void
    {
        $harness = ModelHarness::for(new HarnessCounter(), width: 20, height: 4)
            ->dispatch(new KeyMsg('+'));

        $frame = $harness->renderFrame();

        self::assertSame(20, $frame->width);
        self::assertSame(4, $frame->height);
        self::assertSame('Count: 1', $harness->renderScreen()->getTextAt(0, 0, 8));
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
        self::assertSame('37x11', $harness->renderScreen()->getTextAt(0, 0, 5));
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
