<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use ArrayObject;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Context\RenderPass;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Runtime\Model;
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Runtime\RenderRevisioned;
use Elmphant\Tui\Tests\Support\GuardedTerminal;
use Elmphant\Tui\Tests\Support\Ping;
use Elmphant\Tui\Tests\Support\Stop;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProgramRedrawTest extends TestCase
{
    #[Test]
    public function aModelThatAnswersWithItselfIsNotDrawnAgain(): void
    {
        self::assertSame(1, self::framesDrawn(new PingedModel(new ArrayObject(), PingedModel::KEEPS_ITSELF)));
    }

    #[Test]
    public function aNewModelIsDrawnAgain(): void
    {
        self::assertSame(2, self::framesDrawn(new PingedModel(new ArrayObject(), PingedModel::CHANGES)));
    }

    #[Test]
    public function aNewModelWithTheSameRenderRevisionIsNotDrawnAgain(): void
    {
        self::assertSame(1, self::framesDrawn(new RevisionedPingedModel(new ArrayObject(), revisionStep: 0)));
    }

    #[Test]
    public function aNewRenderRevisionIsDrawnAgain(): void
    {
        self::assertSame(2, self::framesDrawn(new RevisionedPingedModel(new ArrayObject(), revisionStep: 1)));
    }

    private static function framesDrawn(PingedModel|RevisionedPingedModel $model): int
    {
        Program::new($model)->withTerminal(GuardedTerminal::of(20, 3))->start();

        return \count($model->frames);
    }
}

final readonly class PingedModel implements Model
{
    public const KEEPS_ITSELF = 'keeps itself';
    public const CHANGES = 'changes';

    /**
     * @param ArrayObject<int, true> $frames
     */
    public function __construct(
        public ArrayObject $frames,
        private string $onPing,
    ) {
    }

    public function init(): ?Cmd
    {
        return Cmd::after(0.05, static fn (): Message => new Ping());
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return match (true) {
            $message instanceof Ping => new UpdateResult(
                $this->onPing === self::CHANGES ? new self($this->frames, $this->onPing) : $this,
                Cmd::after(0.05, static fn (): Message => new Stop()),
            ),
            $message instanceof Stop => new UpdateResult($this, Cmd::quit()),
            default => new UpdateResult($this),
        };
    }

    public function render(Frame $frame, RenderPass $pass): void
    {
        $this->frames->append(true);
    }
}

final readonly class RevisionedPingedModel implements Model, RenderRevisioned
{
    /**
     * @param ArrayObject<int, true> $frames
     */
    public function __construct(
        public ArrayObject $frames,
        private int $revisionStep,
        private int $revision = 0,
    ) {
    }

    public function init(): ?Cmd
    {
        return Cmd::after(0.05, static fn (): Message => new Ping());
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return match (true) {
            $message instanceof Ping => new UpdateResult(
                new self($this->frames, $this->revisionStep, $this->revision + $this->revisionStep),
                Cmd::after(0.05, static fn (): Message => new Stop()),
            ),
            $message instanceof Stop => new UpdateResult($this, Cmd::quit()),
            default => new UpdateResult($this),
        };
    }

    public function renderRevision(): int
    {
        return $this->revision;
    }

    public function render(Frame $frame, RenderPass $pass): void
    {
        $this->frames->append(true);
    }
}
