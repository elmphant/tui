<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Core;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\Reducer;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use TypeError;

final class UpdateResultTest extends TestCase
{
    #[Test]
    public function aResultCannotContainAScalarInPlaceOfAModel(): void
    {
        $this->expectException(TypeError::class);

        UpdateResult::keep('not a model');
    }

    #[Test]
    public function aResultCannotContainAnObjectThatIsNotAReducer(): void
    {
        $this->expectException(TypeError::class);

        UpdateResult::keep(new stdClass());
    }

    #[Test]
    public function keepReturnsResultWithModelAndNoCommand(): void
    {
        $model = new UpdateResultProbe();

        $result = UpdateResult::keep($model);

        self::assertSame($model, $result->model);
        self::assertNull($result->command);
    }

    #[Test]
    public function changedAcceptsAModelAndOptionalCommand(): void
    {
        $model = new UpdateResultProbe();
        $command = Cmd::quit();

        $withCommand = UpdateResult::changed($model, $command);
        $withoutCommand = UpdateResult::changed($model);

        self::assertSame($model, $withCommand->model);
        self::assertSame($command, $withCommand->command);
        self::assertSame($model, $withoutCommand->model);
        self::assertNull($withoutCommand->command);
    }

    #[Test]
    public function withCommandReplacesOrClearsTheCommandAndPreservesTheModel(): void
    {
        $model = new UpdateResultProbe();
        $command = Cmd::quit();
        $result = UpdateResult::keep($model);

        $withCommand = $result->withCommand($command);
        $withoutCommand = $withCommand->withCommand(null);

        self::assertSame($model, $withCommand->model);
        self::assertSame($command, $withCommand->command);
        self::assertSame($model, $withoutCommand->model);
        self::assertNull($withoutCommand->command);
    }

    #[Test]
    public function withCommandReturnsSameInstanceWhenTheCommandIsUnchanged(): void
    {
        $command = Cmd::quit();
        $result = UpdateResult::changed(new UpdateResultProbe(), $command);

        self::assertSame($result, $result->withCommand($command));
    }

    #[Test]
    public function mapModelTransformsTheModelAndPreservesTheCommand(): void
    {
        $model = new UpdateResultProbe(1);
        $command = Cmd::quit();
        $result = UpdateResult::changed($model, $command);

        $mapped = $result->mapModel(
            static fn (UpdateResultProbe $current): UpdateResultProbe => new UpdateResultProbe($current->value + 1),
        );

        self::assertNotSame($result, $mapped);
        self::assertSame(2, $mapped->model->value);
        self::assertSame($command, $mapped->command);
    }

    #[Test]
    public function mapModelReturnsSameInstanceWhenTheModelIsUnchanged(): void
    {
        $result = UpdateResult::keep(new UpdateResultProbe());

        self::assertSame($result, $result->mapModel(static fn (mixed $model): mixed => $model));
    }

    #[Test]
    public function mapCommandTransformsTheCommandAndPreservesTheModel(): void
    {
        $model = new UpdateResultProbe();
        $replacement = Cmd::quit();
        $result = UpdateResult::changed($model, Cmd::tick(0.1));

        $mapped = $result->mapCommand(static fn (Cmd $command): Cmd => $replacement);

        self::assertNotSame($result, $mapped);
        self::assertSame($model, $mapped->model);
        self::assertSame($replacement, $mapped->command);
    }

    #[Test]
    public function mapCommandElidesTheCallbackAndAllocationWithoutACommand(): void
    {
        $called = false;
        $result = UpdateResult::keep(new UpdateResultProbe());

        $mapped = $result->mapCommand(static function (Cmd $command) use (&$called): Cmd {
            $called = true;

            return $command;
        });

        self::assertFalse($called);
        self::assertSame($result, $mapped);
    }

    #[Test]
    public function mapCommandReturnsSameInstanceWhenTheCommandIsUnchanged(): void
    {
        $result = UpdateResult::changed(new UpdateResultProbe(), Cmd::quit());

        self::assertSame($result, $result->mapCommand(static fn (Cmd $command): Cmd => $command));
    }
}

final class UpdateResultProbe implements Reducer
{
    public function __construct(public int $value = 0)
    {
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return UpdateResult::keep($this);
    }
}
