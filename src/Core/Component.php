<?php

declare(strict_types=1);

namespace Elmphant\Tui\Core;

use Closure;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Widget\Interactive;
use Elmphant\Tui\Widget\SingleMount;
use Elmphant\Tui\Widget\Widget;
use Elmphant\Tui\Widget\WidgetContainer;
use InvalidArgumentException;
use LogicException;
use ReflectionFunction;
use UnitEnum;

/**
 * An interactive widget bound to a stable ID and an immutable App replacement.
 *
 * @template TWidget of Interactive
 */
final readonly class Component implements Widget, WidgetContainer, SingleMount
{
    /**
     * @param TWidget $widget
     * @param Closure $replace validated by bind()
     */
    private function __construct(
        public ComponentId $id,
        public Interactive $widget,
        private Closure $replace,
    ) {
    }

    /**
     * @template TBoundApp of App
     * @template TBoundWidget of Interactive
     *
     * @param TBoundWidget                                $widget
     * @param Closure(TBoundApp, TBoundWidget): TBoundApp $replace
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
        $this->widget->render($frame, $area, $context);
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
     * @param TWidget $widget
     */
    public function replaceIn(App $app, Interactive $widget): App
    {
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
