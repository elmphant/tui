<?php

declare(strict_types=1);

namespace Elmphant\Tui\Application;

use Closure;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\Reducer;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Widget\Widget;
use Elmphant\Tui\Widget\WidgetContainer;

/**
 * A sub-model placed inside an App under a name. Its components are known as
 * `name/component`, and answers to what the model or its components ask for come
 * back to the model. So an App can host a model it knows nothing about.
 *
 * @template TApp of App
 * @template TModel of Reducer
 */
final readonly class Mount implements Widget, WidgetContainer
{
    /**
     * @param TModel                      $model
     * @param Closure(TApp, TModel): TApp $mount
     */
    private function __construct(
        public ComponentId $id,
        public Reducer $model,
        private Widget $content,
        private Closure $mount,
    ) {
    }

    /**
     * @template TBoundApp of App
     * @template TBoundModel of Reducer
     *
     * @param TBoundModel                                $model
     * @param Closure(TBoundApp, TBoundModel): TBoundApp $mount
     *
     * @return self<TBoundApp, TBoundModel>
     */
    public static function named(ComponentId|string $id, Reducer $model, Widget $view, Closure $mount): self
    {
        return new self(\is_string($id) ? ComponentId::named($id) : $id, $model, $view, $mount);
    }

    public function sizing(): Sizing
    {
        return $this->content->sizing();
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        $context->withinMount($this->id, fn () => $this->content->render($frame, $area, $context));
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
        return $component->nestedIn($this->model, $this->mount, $this->addressed(...))->under($this->id);
    }

    /**
     * Marks what the model or its components ask for, so the answer comes back
     * here. Effect ids and focus targets inside it belong to this mount.
     *
     * @internal
     */
    public function addressed(?Cmd $command): ?Cmd
    {
        $id = $this->id;

        return $command?->map(static fn (Message $message): Message => new MountedMsg($id, $message))->scopedTo($id->value);
    }

    /**
     * @internal
     *
     * @param TApp   $app
     * @param TModel $model
     *
     * @return TApp
     */
    public function writtenBack(App $app, Reducer $model): App
    {
        return ($this->mount)($app, $model);
    }
}
