<?php

declare(strict_types=1);

namespace Elmphant\Tui\Core;

use Closure;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Widget\Dispatch\SingleMount;
use Elmphant\Tui\Widget\Interactive;
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
 * @template TWidget of Widget
 */
final readonly class Component implements Widget, WidgetContainer, SingleMount
{
    /**
     * @param TWidget      $widget
     * @param Closure|null $replace validated by bind(); null when named()
     * @param Closure|null $address set by the Mount that owns this, if any
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
        return new self(
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
     * joins to one.
     *
     * @template TBoundOwner of Reducer
     * @template TBoundWidget of Interactive
     *
     * @param TBoundWidget                                    $widget
     * @param Closure(TBoundOwner, TBoundWidget): TBoundOwner $replace
     *
     * @return self<TBoundWidget>
     */
    public static function bind(ComponentId|string $id, Interactive $widget, Closure $replace): self
    {
        self::assertSafeReplacement($replace);

        return new self(
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
    public function childWidgets(Rect $area): iterable
    {
        yield [$this->widget, $area];
    }

    /**
     * @internal
     *
     * @param TWidget $widget
     *
     * @return self<TWidget>
     */
    public function withWidget(Widget $widget): self
    {
        return new self(id: $this->id, widget: $widget, replace: $this->replace, address: $this->address);
    }

    /**
     * @internal
     *
     * @param Closure $mount validated by Mount::of()
     *
     * @return self<TWidget>
     */
    public function nestedIn(Reducer $owner, Closure $mount, ?Closure $address = null): self
    {
        $replace = $this->replace;

        if ($replace === null) {
            return $this;
        }

        return new self(
            id: $this->id,
            widget: $this->widget,
            replace: static fn (App $app, Interactive $widget): App => $mount($app, $replace($owner, $widget)),
            address: $address,
        );
    }

    /**
     * What this component's own effects say, marked as coming from it. A host that
     * mounts several models cannot tell a bare message apart once it is out.
     *
     * @internal
     */
    public function addressed(?Cmd $command): ?Cmd
    {
        $address = $this->address;

        return $command === null || $address === null ? $command : $command->map($address);
    }

    public function replaceIn(App $app, Interactive $widget): App
    {
        if ($this->replace === null) {
            throw new LogicException(sprintf('Component "%s" was named, not bound: its host drives it, so nothing can be written back', $this->id->value));
        }

        $updated = ($this->replace)($app, $widget);

        if ($updated::class !== $app::class) {
            throw new LogicException(sprintf('Component "%s" replacement must return %s, got %s', $this->id->value, $app::class, $updated::class));
        }

        return $updated;
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
