<?php

declare(strict_types=1);

namespace Elmphant\Tui\Application;

use Closure;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Cmd\CancelScopeCmd;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\Reducer;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Widget\Widget;
use Elmphant\Tui\Widget\WidgetContainer;
use LogicException;

/**
 * Mount a sub-model under a name: component IDs become `name/component`, and effect results return to that model.
 *
 * @template TModel of Reducer
 */
final readonly class Mount implements Widget, WidgetContainer
{
    /**
     * @param TModel  $model
     * @param Closure $mount typed by named()
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
     * @return self<TBoundModel>
     */
    public static function named(ComponentId|string $id, Reducer $model, Widget $view, Closure $mount): self
    {
        return new self(\is_string($id) ? ComponentId::named($id) : $id, $model, $view, $mount);
    }

    /**
     * Send a message to the parent host instead of back to this mounted model.
     */
    public static function toHost(Message $message): Cmd
    {
        return Cmd::message(new TellsTheHost($message));
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
     * Scope effect IDs and focus targets to this mount, and route results back to it.
     */
    public function addressed(?Cmd $command): ?Cmd
    {
        $id = $this->id;

        return $command?->map(static fn (Message $message): Message => $message instanceof TellsTheHost ? $message->message : new MountedMsg($id, $message))->scopedTo($id->value);
    }

    /**
     * @internal what the App asks for once this mount is gone from it
     */
    public function left(): Cmd
    {
        return (new CancelScopeCmd())->scopedTo($this->id->value);
    }

    /**
     * @internal the App and the model are the ones this was mounted with; App checks the class it gets back
     */
    public function writtenBack(App $app, Reducer $model): App
    {
        $updated = ($this->mount)($app, $model);

        return $updated instanceof App
            ? $updated
            : throw new LogicException(sprintf('Mount "%s" must write back an App, got %s', $this->id->value, get_debug_type($updated)));
    }
}
