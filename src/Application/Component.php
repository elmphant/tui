<?php

declare(strict_types=1);

namespace Elmphant\Tui\Application;

use Closure;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Cmd\BatchCmd;
use Elmphant\Tui\Core\Cmd\FocusCmd;
use Elmphant\Tui\Core\Cmd\MappedCmd;
use Elmphant\Tui\Core\Cmd\ScopedCmd;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\Reducer;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Message\ActionMsg;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Widget\Focus\GraftsItsChildren;
use Elmphant\Tui\Widget\Interactive;
use Elmphant\Tui\Widget\MeasureHeight;
use Elmphant\Tui\Widget\SingleMount;
use Elmphant\Tui\Widget\Widget;
use Elmphant\Tui\Widget\WidgetContainer;
use InvalidArgumentException;
use LogicException;
use ReflectionFunction;
use UnitEnum;

/**
 * A widget with a stable ID, either bound to an App replacement or named for host-managed input.
 *
 * @template-covariant TWidget of Widget
 */
readonly class Component implements Widget, WidgetContainer, SingleMount
{
    /**
     * @param TWidget                                              $widget
     * @param Closure|null                                         $writeBack validated by bind(); null when named()
     * @param (Closure(?Cmd): ?Cmd)|null                           $address   set by the Mount that owns this, if any
     * @param list<array{class-string, Closure, ?string, ?string}> $handlers  validated by on() and withAction(): the message class, the handler, the action it answers and its label
     */
    private function __construct(
        public ComponentId $id,
        public Widget $widget,
        private ?Closure $writeBack,
        private ?Closure $address = null,
        private ?Keybindings $actions = null,
        private array $handlers = [],
    ) {
    }

    /**
     * A named widget participates in focus and rendering, but its host must route input and store updates.
     * Inside a bound component, that component routes and stores it, and the name only addresses it.
     *
     * @template TNamedWidget of Widget
     *
     * @param TNamedWidget $widget
     *
     * @return self<TNamedWidget>
     */
    public static function named(ComponentId|string $id, Widget $widget): self
    {
        return self::wrapping(
            is_string($id) ? ComponentId::named($id) : $id,
            $widget,
            null,
        );
    }

    /**
     * @internal
     */
    public function writesBack(): bool
    {
        return $this->writeBack !== null;
    }

    /**
     * The replacement receives the owner, updated widget and triggering message; it may return commands through UpdateResult.
     * Committing widgets are written back even when unchanged, so repeated commits reach the host.
     *
     * @template TBoundOwner of Reducer
     * @template TBoundWidget of Interactive
     *
     * @param TBoundWidget                                                                         $widget
     * @param Closure(TBoundOwner, TBoundWidget, Message): (TBoundOwner|UpdateResult<TBoundOwner>) $writeBack
     *
     * @return self<TBoundWidget>
     */
    public static function bind(ComponentId|string $id, Interactive $widget, Closure $writeBack): self
    {
        self::assertSafeReplacement($writeBack);

        return self::wrapping(
            is_string($id) ? ComponentId::named($id) : $id,
            $widget,
            $writeBack,
        );
    }

    /**
     * Runs after the widget is stored, on the stored owner, for a message of that class. The first match answers.
     *
     * @template TOwner of Reducer
     * @template TMessage of Message
     *
     * @param class-string<TMessage>                                   $messageClass
     * @param Closure(TOwner, TMessage): (TOwner|UpdateResult<TOwner>) $handler
     *
     * @return self<TWidget>
     */
    public function on(string $messageClass, Closure $handler): self
    {
        self::assertSafeReplacement($handler);

        return self::wrapping($this->id, $this->widget, $this->writeBack, $this->address, $this->actions, [...$this->handlers, [$messageClass, $handler, null, null]]);
    }

    /**
     * Keys that run the handler on the stored owner while the keyboard is on this component or inside it,
     * before the App's own keybindings. The footer and the key help name them by the label.
     *
     * @template TOwner of Reducer
     *
     * @param list<string>                                   $keys
     * @param Closure(TOwner): (TOwner|UpdateResult<TOwner>) $handler
     *
     * @return self<TWidget>
     */
    public function withAction(string $name, array $keys, Closure $handler, ?string $label = null): self
    {
        self::assertSafeReplacement($handler);

        return self::wrapping(
            $this->id,
            $this->widget,
            $this->writeBack,
            $this->address,
            $this->actions()->overriddenBy(new Keybindings([$name => $keys])),
            [[ActionMsg::class, $handler, $name, $label], ...$this->handlers],
        );
    }

    /**
     * @internal
     */
    public function actions(): Keybindings
    {
        return $this->actions ?? Keybindings::empty();
    }

    /**
     * @internal
     */
    public function labelOf(string $action): ?string
    {
        foreach ($this->handlers as [, , $answers, $label]) {
            if ($answers === $action) {
                return $label;
            }
        }

        return null;
    }

    /**
     * @internal
     */
    public function mountName(): string
    {
        return $this->id->value;
    }

    public function sizing(): Sizing
    {
        return $this->widget->sizing();
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        $context->withinComponent($this->id, fn () => $context->draw($this->widget, $frame, $area));
    }

    /**
     * @return iterable<Widget>
     */
    public function children(): iterable
    {
        yield $this->widget;
    }

    /**
     * @return iterable<array{Widget, Rect}>
     */
    public function placedChildren(Rect $area): iterable
    {
        yield [$this->widget, $area];
    }

    /**
     * Update the child inside its container before calling the host's replacement.
     *
     * @internal
     *
     * @return self<Interactive>
     */
    public function childAt(int $index, Interactive $child): self
    {
        return $this->childThrough([$index], $child);
    }

    /**
     * A child inside passive grafting containers (a Layout, a Box): the first index is this widget's child,
     * each further index the child of the container before it, and the write-back rebuilds them inside out.
     *
     * @internal
     *
     * A Component::named() child keeps its name, its actions and its handlers
     * @internal
     *
     * @param non-empty-list<int>    $path
     * @param self<Interactive>|null $named
     *
     * @return self<Interactive>
     */
    public function childThrough(array $path, Interactive $child, ?self $named = null): self
    {
        $id = $named->id ?? ComponentId::named($this->id->value . '/' . implode('/', $path));
        $container = $this->widget;

        if ($this->writeBack === null || !$container instanceof GraftsItsChildren) {
            return self::wrapping($id, $child, null, $this->address, $named?->actions, $named->handlers ?? []);
        }

        $parent = $this;

        return self::wrapping(
            $id,
            $child,
            static function (Reducer $owner, Interactive $updated, Message $message) use ($parent, $container, $path): UpdateResult {
                $grafted = ComponentWriteBack::grafted($container, $path, $updated);
                assert($grafted instanceof Interactive);

                return $parent->answered($owner, $grafted, $message);
            },
            $this->address,
            $named?->actions,
            $named->handlers ?? [],
        );
    }

    /**
     * @internal
     *
     * @return self<Widget>
     */
    public function withWidget(Widget $widget): self
    {
        return self::wrapping($this->id, $widget, $this->writeBack, $this->address, $this->actions, $this->handlers);
    }

    /**
     * @internal
     *
     * @param Closure             $mountWriteBack validated by Mount::named()
     * @param Closure(?Cmd): ?Cmd $address
     *
     * @return self<TWidget>
     */
    public function nestedIn(Reducer $owner, Closure $mountWriteBack, Closure $address): self
    {
        $inner = $this;

        return self::wrapping(
            id: $this->id,
            widget: $this->widget,
            writeBack: $this->writeBack === null ? null : static function (App $app, Interactive $widget, Message $message) use ($owner, $mountWriteBack, $inner, $address): UpdateResult {
                $answered = $inner->answered($owner, $widget, $message);

                return new UpdateResult($mountWriteBack($app, $answered->model), $address($answered->command));
            },
            address: $address,
            actions: $this->actions,
        );
    }

    /**
     * @internal
     *
     * @return self<TWidget>
     */
    public function under(ComponentId $mount): self
    {
        return self::wrapping(ComponentId::named($mount->value . '/' . $this->id->value), $this->widget, $this->writeBack, $this->address, $this->actions, $this->handlers);
    }

    /**
     * @internal
     */
    public function addressed(?Cmd $command): ?Cmd
    {
        if ($command === null) {
            return null;
        }

        $resolved = $this->withChildFocusResolved($command);

        return $this->address === null ? $resolved : ($this->address)($resolved);
    }

    /**
     * @internal a closure that runs on a later owner must be static and capture no objects, or it reads a stale one
     */
    public static function assertSafeReplacement(Closure $writeBack): void
    {
        $reflection = new ReflectionFunction($writeBack);

        if (!$reflection->isStatic()) {
            throw new InvalidArgumentException('Component replacement closure must be static; use the supplied $app instead of $this');
        }

        foreach ($reflection->getStaticVariables() as $captured) {
            if (self::containsObjectState($captured)) {
                throw new InvalidArgumentException('Component replacement closure must not capture object state; use the supplied $app');
            }
        }
    }

    /**
     * @internal
     *
     * @return UpdateResult<App>
     */
    public function replaceIn(App $app, Interactive $widget, Message $message): UpdateResult
    {
        $result = $this->answered($app, $widget, $message);

        if ($result->model::class !== $app::class) {
            throw new LogicException(sprintf('Component "%s" replacement must return %s, got %s', $this->id->value, $app::class, get_debug_type($result->model)));
        }

        /** @var UpdateResult<App> $result */
        return $result;
    }

    /**
     * The write-back stores the widget, then the first handler for the message answers on the stored owner.
     *
     * @return UpdateResult<Reducer>
     */
    private function answered(Reducer $owner, Interactive $widget, Message $message): UpdateResult
    {
        if ($this->writeBack === null) {
            throw new LogicException(sprintf('Component "%s" was named, not bound: its host drives it, so nothing can be written back', $this->id->value));
        }

        $stored = self::asResult(($this->writeBack)($owner, $widget, $message));

        foreach ($this->handlers as [$messageClass, $handler, $action]) {
            if (!$message instanceof $messageClass || ($action !== null && (!$message instanceof ActionMsg || $message->action !== $action))) {
                continue;
            }

            $handled = self::asResult($handler($stored->model, $message));

            return new UpdateResult($handled->model, Cmd::batch($stored->command, $handled->command));
        }

        return $stored;
    }

    /**
     * @return UpdateResult<Reducer>
     */
    private static function asResult(mixed $answered): UpdateResult
    {
        if ($answered instanceof UpdateResult) {
            return $answered;
        }
        if (!$answered instanceof Reducer) {
            throw new LogicException(sprintf('A bind closure or handler must return the owner or an UpdateResult, got %s', get_debug_type($answered)));
        }

        return new UpdateResult($answered);
    }

    private function withChildFocusResolved(Cmd $command): Cmd
    {
        return match (true) {
            $command instanceof FocusCmd => $command->resolvedUnder($this->id),
            $command instanceof BatchCmd => new BatchCmd(array_map($this->withChildFocusResolved(...), $command->commands)),
            $command instanceof MappedCmd => new MappedCmd($this->withChildFocusResolved($command->inner), $command->wrap),
            $command instanceof ScopedCmd => new ScopedCmd($this->withChildFocusResolved($command->inner), $command->scope),
            default => $command,
        };
    }

    /**
     * @template TWrapped of Widget
     *
     * @param TWrapped                                             $widget
     * @param list<array{class-string, Closure, ?string, ?string}> $handlers
     *
     * @return self<TWrapped>
     */
    private static function wrapping(ComponentId $id, Widget $widget, ?Closure $writeBack, ?Closure $address = null, ?Keybindings $actions = null, array $handlers = []): self
    {
        return $widget instanceof MeasureHeight
            ? new MeasuredComponent($id, $widget, $writeBack, $address, $actions, $handlers)
            : new self($id, $widget, $writeBack, $address, $actions, $handlers);
    }

    private static function containsObjectState(mixed $value): bool
    {
        if ($value instanceof UnitEnum) {
            return false;
        }

        if (is_object($value) || is_resource($value)) {
            return true;
        }

        if (!is_array($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (self::containsObjectState($item)) {
                return true;
            }
        }

        return false;
    }
}
