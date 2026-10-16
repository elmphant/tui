<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Cmd\BatchCmd;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\Reducer;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Input\KeybindingsAware;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Tests\Support\TestMessage;
use Elmphant\Tui\Widget\Dispatch\ChildDispatcher;
use Elmphant\Tui\Widget\Interactive;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ChildDispatcherTest extends TestCase
{
    #[Test]
    public function dispatchOnePreservesIdentityForAnUnchangedChild(): void
    {
        $child = new DispatchProbe();
        $rect = new Rect(2, 3, 10, 5);

        $result = (new ChildDispatcher())->dispatchOne($child, new TestMessage('message'), $rect);

        self::assertSame($child, $result->child);
        self::assertFalse($result->changed);
        self::assertNull($result->command);
        self::assertSame($rect, $child->lastRect);
    }

    #[Test]
    public function dispatchOneReturnsAChangedChildAndCommand(): void
    {
        $replacement = new DispatchProbe();
        $command = Cmd::message(new TestMessage('done'));
        $child = new DispatchProbe(replacement: $replacement, command: $command);

        $result = (new ChildDispatcher())->dispatchOne($child, new TestMessage('message'), Rect::fromSize(10, 5));

        self::assertSame($replacement, $result->child);
        self::assertTrue($result->changed);
        self::assertSame($command, $result->command);
    }

    #[Test]
    public function dispatchOnePropagatesGlobalKeybindingsWithoutTreatingTheWrapperAsAChange(): void
    {
        $state = new BindingProbeState();
        $bindings = new Keybindings(['save' => ['ctrl+s']]);
        $child = new BindingAwareDispatchProbe($state);

        $result = (new ChildDispatcher($bindings))->dispatchOne($child, new TestMessage('message'), Rect::fromSize(10, 5));

        self::assertSame($bindings, $state->seenGlobalBindings);
        self::assertSame($child, $result->child);
        self::assertFalse($result->changed);
    }

    #[Test]
    public function dispatchOneDoesNotWrapWithoutGlobalKeybindings(): void
    {
        $state = new BindingProbeState();
        $child = new BindingAwareDispatchProbe($state);

        (new ChildDispatcher())->dispatchOne($child, new TestMessage('message'), Rect::fromSize(10, 5));

        self::assertNull($state->seenGlobalBindings);
    }

    #[Test]
    public function dispatchOneRejectsANonInteractiveResultModel(): void
    {
        $child = new DispatchProbe(modelOverride: new NonInteractiveReducer());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Interactive child update must return an Interactive model');

        (new ChildDispatcher())->dispatchOne($child, new TestMessage('message'), Rect::fromSize(10, 5));
    }

    #[Test]
    public function dispatchMapCollectsOnlyChangesAndBatchesCommands(): void
    {
        $replacement = new DispatchProbe();
        $firstCommand = Cmd::message(new TestMessage('first'));
        $secondCommand = Cmd::message(new TestMessage('second'));
        $children = [
            'unchanged' => new DispatchProbe(command: $firstCommand),
            'changed' => new DispatchProbe(replacement: $replacement, command: $secondCommand),
        ];
        $rects = [
            'unchanged' => Rect::fromSize(10, 1),
            'changed' => new Rect(0, 1, 10, 1),
        ];

        $result = (new ChildDispatcher())->dispatchMap($children, $rects, new TestMessage('message'));

        self::assertSame(['changed' => $replacement], $result->overrides);
        self::assertTrue($result->changed());
        self::assertInstanceOf(BatchCmd::class, $result->command);
        self::assertSame([$firstCommand, $secondCommand], $result->command->commands);
    }

    #[Test]
    public function dispatchMapPreservesZeroAndOneCommandShapes(): void
    {
        $rect = Rect::fromSize(10, 1);
        $none = (new ChildDispatcher())->dispatchMap(
            ['child' => new DispatchProbe()],
            ['child' => $rect],
            new TestMessage('message'),
        );
        $command = Cmd::message(new TestMessage('one'));
        $one = (new ChildDispatcher())->dispatchMap(
            ['child' => new DispatchProbe(command: $command)],
            ['child' => $rect],
            new TestMessage('message'),
        );

        self::assertSame([], $none->overrides);
        self::assertFalse($none->changed());
        self::assertNull($none->command);
        self::assertSame($command, $one->command);
    }

    #[Test]
    public function dispatchMapThrowsForAMissingRect(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("Missing rect for child key 'missing'");

        (new ChildDispatcher())->dispatchMap(
            ['missing' => new DispatchProbe()],
            [],
            new TestMessage('message'),
        );
    }

    #[Test]
    public function dispatchListPreservesOriginalIntegerIndices(): void
    {
        $replacement = new DispatchProbe();
        $command = Cmd::message(new TestMessage('done'));
        $result = (new ChildDispatcher())->dispatchList(
            [
                2 => new DispatchProbe(),
                5 => new DispatchProbe(replacement: $replacement, command: $command),
            ],
            [
                2 => Rect::fromSize(10, 1),
                5 => new Rect(0, 1, 10, 1),
            ],
            new TestMessage('message'),
        );

        self::assertSame([5 => $replacement], $result->overrides);
        self::assertTrue($result->changed());
        self::assertSame($command, $result->command);
    }

    #[Test]
    public function dispatchListThrowsForAMissingRect(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Missing rect for child index 4');

        (new ChildDispatcher())->dispatchList(
            [4 => new DispatchProbe()],
            [],
            new TestMessage('message'),
        );
    }
}

final class DispatchProbe implements Interactive
{
    public ?Rect $lastRect = null;

    public function __construct(
        private readonly ?Interactive $replacement = null,
        private readonly ?Cmd $command = null,
        private readonly mixed $modelOverride = null,
    ) {
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::flex());
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        $this->lastRect = $area;

        return new UpdateResult($this->modelOverride ?? $this->replacement ?? $this, $this->command);
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
    }
}

final class NonInteractiveReducer implements Reducer
{
    public function update(Message $message, Rect $area): UpdateResult
    {
        return UpdateResult::ignored($this);
    }
}

final class BindingProbeState
{
    public ?Keybindings $seenGlobalBindings = null;
}

final readonly class BindingAwareDispatchProbe implements Interactive, KeybindingsAware
{
    public function __construct(
        private BindingProbeState $state,
        private ?Keybindings $globalBindings = null,
    ) {
    }

    public static function defaultKeybindings(): Keybindings
    {
        return Keybindings::empty();
    }

    public function effectiveKeybindings(): Keybindings
    {
        return $this->globalBindings ?? Keybindings::empty();
    }

    public function withKeybindings(?Keybindings $keybindings): static
    {
        return $this;
    }

    public function withGlobalKeybindings(?Keybindings $keybindings): static
    {
        return new self($this->state, $keybindings);
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::flex());
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        $this->state->seenGlobalBindings = $this->globalBindings;

        return UpdateResult::ignored($this);
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
    }
}
