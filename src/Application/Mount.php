<?php

declare(strict_types=1);

namespace Elmphant\Tui\Application;

use Closure;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\Reducer;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Widget\Widget;
use Elmphant\Tui\Widget\WidgetContainer;

/**
 * A sub-model embedded in an App, with the drawing it asked for and the way back
 * into the App. What it mounts binds its own components, so an App may host a
 * model it knows nothing about.
 *
 * @template TApp of App
 * @template TModel of Reducer
 */
final readonly class Mount implements Widget, WidgetContainer
{
    /**
     * @param TModel                        $model
     * @param Closure(TApp, TModel): TApp   $mount
     * @param Closure(Message):Message|null $address
     */
    private function __construct(
        public Reducer $model,
        private Widget $content,
        private Closure $mount,
        private ?Closure $address = null,
    ) {
    }

    /**
     * A component this mounts speaks for the model, not for the App that hosts it.
     * $address wraps whatever its commands emit, so a host with several mounted
     * models can tell which one a message came from once it is out.
     *
     * @template TBoundApp of App
     * @template TBoundModel of Reducer
     *
     * @param TBoundModel                                $model
     * @param Closure(TBoundApp, TBoundModel): TBoundApp $mount
     * @param Closure(Message):Message|null              $address
     *
     * @return self<TBoundApp, TBoundModel>
     */
    public static function of(Reducer $model, Widget $content, Closure $mount, ?Closure $address = null): self
    {
        return new self($model, $content, $mount, $address);
    }

    public function sizing(): Sizing
    {
        return $this->content->sizing();
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        $this->content->render($frame, $area, $context);
    }

    /**
     * @return iterable<Widget>
     */
    public function children(): iterable
    {
        yield $this->content;
    }

    /**
     * @return iterable<array{Widget, Rect}>
     */
    public function placedChildren(Rect $area): iterable
    {
        yield [$this->content, $area];
    }

    /**
     * @internal
     *
     * @template TWidget of \Elmphant\Tui\Widget\Interactive
     *
     * @param Component<TWidget> $component
     *
     * @return Component<TWidget>
     */
    public function own(Component $component): Component
    {
        return $component->nestedIn($this->model, $this->mount, $this->address);
    }
}
