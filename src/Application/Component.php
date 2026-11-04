<?php

declare(strict_types=1);

namespace Elmphant\Tui\Application;

use Closure;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\Reducer;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
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
     * @param TWidget                    $widget
     * @param Closure|null               $writeBack validated by bind(); null when named()
     * @param (Closure(?Cmd): ?Cmd)|null $address   set by the Mount that owns this, if any
     */
    private function __construct(
        public ComponentId $id,
        public Widget $widget,
        private ?Closure $writeBack,
        private ?Closure $address = null,
        private ?Keybindings $actions = null,
    ) {
    }

    /**
     * A named widget participates in focus and rendering, but its host must route input and store updates.
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
     * Actions the App answers in onAction() only while the keyboard is on this component or inside it,
     * before its application keybindings. The footer names them only then.
     *
     * @return self<TWidget>
     */
    public function withActions(Keybindings $actions): self
    {
        return self::wrapping($this->id, $this->widget, $this->writeBack, $this->address, $actions);
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
        $context->withinComponent($this->id, function () use ($frame, $area, $context): void {
            $this->widget->render($frame, $area, $context);
        });
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
        $id = ComponentId::named($this->id->value . '/' . $index);
        $writeBack = $this->writeBack;
        $container = $this->widget;

        if ($writeBack === null || !$container instanceof GraftsItsChildren) {
            return self::wrapping(id: $id, widget: $child, writeBack: null, address: $this->address);
        }

        return self::wrapping(
            id: $id,
            widget: $child,
            writeBack: static fn (Reducer $owner, Interactive $updated, Message $message): Reducer|UpdateResult => $writeBack($owner, $container->withChildAt($index, $updated), $message),
            address: $this->address,
        );
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
        $writeBack = $this->writeBack;

        return self::wrapping(
            id: $this->id,
            widget: $this->widget,
            writeBack: $writeBack === null ? null : static function (App $app, Interactive $widget, Message $message) use ($owner, $mountWriteBack, $writeBack, $address): UpdateResult {
                $answered = $writeBack($owner, $widget, $message);

                return $answered instanceof UpdateResult
                    ? new UpdateResult($mountWriteBack($app, $answered->model), $address($answered->command))
                    : new UpdateResult($mountWriteBack($app, $answered));
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
        return self::wrapping(ComponentId::named($mount->value . '/' . $this->id->value), $this->widget, $this->writeBack, $this->address, $this->actions);
    }

    /**
     * @internal
     */
    public function addressed(?Cmd $command): ?Cmd
    {
        $address = $this->address;

        return $command === null || $address === null ? $command : $address($command);
    }

    /**
     * @internal
     *
     * @return UpdateResult<App>
     */
    public function replaceIn(App $app, Interactive $widget, Message $message): UpdateResult
    {
        if ($this->writeBack === null) {
            throw new LogicException(sprintf('Component "%s" was named, not bound: its host drives it, so nothing can be written back', $this->id->value));
        }

        $answered = ($this->writeBack)($app, $widget, $message);
        $result = $answered instanceof UpdateResult ? $answered : new UpdateResult($answered);

        if ($result->model::class !== $app::class) {
            throw new LogicException(sprintf('Component "%s" replacement must return %s, got %s', $this->id->value, $app::class, get_debug_type($result->model)));
        }

        return $result;
    }

    /**
     * @template TWrapped of Widget
     *
     * @param TWrapped $widget
     *
     * @return self<TWrapped>
     */
    private static function wrapping(ComponentId $id, Widget $widget, ?Closure $writeBack, ?Closure $address = null, ?Keybindings $actions = null): self
    {
        return $widget instanceof MeasureHeight
            ? new MeasuredComponent($id, $widget, $writeBack, $address, $actions)
            : new self($id, $widget, $writeBack, $address, $actions);
    }

    private static function assertSafeReplacement(Closure $writeBack): void
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
