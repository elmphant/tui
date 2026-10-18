<?php

declare(strict_types=1);

namespace Elmphant\Tui\Application;

use Closure;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\Reducer;
use Elmphant\Tui\Core\UpdateResult;
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
 * A widget the tree knows by a stable ID: bound to an immutable App replacement,
 * or named for the keyboard's sake while its host drives it.
 *
 * @template-covariant TWidget of Widget
 */
readonly class Component implements Widget, WidgetContainer, SingleMount
{
    /**
     * @param TWidget                    $widget
     * @param Closure|null               $replace validated by bind(); null when named()
     * @param (Closure(?Cmd): ?Cmd)|null $address set by the Mount that owns this, if any
     */
    private function __construct(
        public ComponentId $id,
        public Widget $widget,
        private ?Closure $replace,
        private ?Closure $address = null,
    ) {
    }

    /**
     * A focusable the tree knows by name, whose state its host still drives. It
     * takes its turn at the keyboard and draws focused like any other, but input
     * is the host's to route: nothing here can write the widget back.
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

    public function writesBack(): bool
    {
        return $this->replace !== null;
    }

    /**
     * The owner is whatever bound it: the root App, or a sub-model that a Mount
     * joins to one. The replacement may answer with an UpdateResult when writing
     * the widget back also asks for work, such as loading what it now shows.
     *
     * @template TBoundOwner of Reducer
     * @template TBoundWidget of Interactive
     *
     * @param TBoundWidget                                                                $widget
     * @param Closure(TBoundOwner, TBoundWidget): (TBoundOwner|UpdateResult<TBoundOwner>) $replace
     *
     * @return self<TBoundWidget>
     */
    public static function bind(ComponentId|string $id, Interactive $widget, Closure $replace): self
    {
        self::assertSafeReplacement($replace);

        return self::wrapping(
            is_string($id) ? ComponentId::named($id) : $id,
            $widget,
            $replace,
        );
    }

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
     * What the child answers is grafted back into the container before the host's
     * replacement sees it, so the host still stores the one widget it bound.
     *
     * @internal
     *
     * @return self<Interactive>
     */
    public function childAt(int $index, Interactive $child): self
    {
        $id = ComponentId::named($this->id->value . '/' . $index);
        $replace = $this->replace;
        $container = $this->widget;

        if ($replace === null || !$container instanceof GraftsItsChildren) {
            return self::wrapping(id: $id, widget: $child, replace: null, address: $this->address);
        }

        return self::wrapping(
            id: $id,
            widget: $child,
            replace: static fn (Reducer $owner, Interactive $updated): Reducer|UpdateResult => $replace($owner, $container->withChildAt($index, $updated)),
            address: $this->address,
        );
    }

    /**
     * @internal
     *
     * @param Closure             $mount   validated by Mount::named()
     * @param Closure(?Cmd): ?Cmd $address
     *
     * @return self<TWidget>
     */
    public function nestedIn(Reducer $owner, Closure $mount, Closure $address): self
    {
        $replace = $this->replace;

        return self::wrapping(
            id: $this->id,
            widget: $this->widget,
            replace: $replace === null ? null : static function (App $app, Interactive $widget) use ($owner, $mount, $replace, $address): UpdateResult {
                $answered = $replace($owner, $widget);

                return $answered instanceof UpdateResult
                    ? new UpdateResult($mount($app, $answered->model), $address($answered->command))
                    : new UpdateResult($mount($app, $answered));
            },
            address: $address,
        );
    }

    /**
     * @internal
     *
     * @return self<TWidget>
     */
    public function under(ComponentId $mount): self
    {
        return self::wrapping(ComponentId::named($mount->value . '/' . $this->id->value), $this->widget, $this->replace, $this->address);
    }

    /**
     * What this component's own effects say, marked as coming from what mounted it.
     *
     * @internal
     */
    public function addressed(?Cmd $command): ?Cmd
    {
        $address = $this->address;

        return $command === null || $address === null ? $command : $address($command);
    }

    /**
     * @return UpdateResult<App>
     */
    public function replaceIn(App $app, Interactive $widget): UpdateResult
    {
        if ($this->replace === null) {
            throw new LogicException(sprintf('Component "%s" was named, not bound: its host drives it, so nothing can be written back', $this->id->value));
        }

        $answered = ($this->replace)($app, $widget);
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
    private static function wrapping(ComponentId $id, Widget $widget, ?Closure $replace, ?Closure $address = null): self
    {
        return $widget instanceof MeasureHeight
            ? new MeasuredComponent($id, $widget, $replace, $address)
            : new self($id, $widget, $replace, $address);
    }

    private static function assertSafeReplacement(Closure $replace): void
    {
        $reflection = new ReflectionFunction($replace);

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
