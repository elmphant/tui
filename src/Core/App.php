<?php

declare(strict_types=1);

namespace Elmphant\Tui\Core;

use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Layout\LayoutSnapshot;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\FloatMouseMsg;
use Elmphant\Tui\Message\KeyEventType;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Message\ResizeMsg;
use Elmphant\Tui\Message\TickMsg;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Runtime\ChildDispatcher;
use Elmphant\Tui\Widget\Focusable;
use Elmphant\Tui\Widget\FocusTree;
use Elmphant\Tui\Widget\Interactive;
use Elmphant\Tui\Widget\Widget;
use LogicException;
use WeakMap;

abstract readonly class App implements Model
{
    use Cloneable;

    /**
     * Declare the widget tree for the current state.
     *
     * Must be a pure function of this instance: the framework memoizes the
     * result (and everything derived from it — component registry, layout)
     * per App value. Reading clocks, randomness, or other external state here
     * yields silently stale renders — including a readonly property holding a
     * mutable object, whose later mutations never reach the screen. For
     * animation, widgets read the frame clock via RenderContext::now() during
     * render instead.
     */
    abstract public function view(): Widget;

    final public function init(): ?Cmd
    {
        return $this->setup();
    }

    final public function render(Frame $frame, RenderContext $context): void
    {
        $area = $frame->area();

        $context->focusOn($this->focusState());

        $context->renderRoot(
            $this->viewWidget(),
            $frame,
            $area,
            LayoutSnapshot::existingPlanFor($this, $area),
        );
    }

    final public function update(Message $message, Rect $screenRect, ?ComponentId $focused = null): UpdateResult
    {
        // Kitty keyboard protocol reports Press AND Release for each
        // keystroke; without dropping Release here, every onKey handler
        // matching a key would fire twice per physical press.
        if ($message instanceof KeyMsg && $message->eventType === KeyEventType::Release) {
            return new UpdateResult($this);
        }

        if ($message instanceof KeyMsg) {
            $action = $this->matchedApplicationAction($message);
            if ($action !== null) {
                return $this->onAction($action, $message);
            }
        }

        if ($message instanceof ResizeMsg) {
            return $this->onResize($message);
        }

        $registry = $this->componentRegistry();
        $components = $registry->all();
        $focusables = $registry->focusables();

        if ($focusables === []) {
            return $this->handleLayerOne($message, $screenRect, $components);
        }

        return $this->handleLayerTwo($message, $screenRect, self::holdingTheKeyboard($focusables, $focused));
    }

    /**
     * How to label the keys that really leave, for a footer to show rather than
     * repeat: a hardcoded hint outlives the binding it was copied from.
     *
     * @return array<string, string>
     */
    public function quitHints(string $description = 'quit'): array
    {
        return $this->applicationKeybindings()
            ->append($this->resolvedQuitBindings())
            ->hints(['quit' => $description]);
    }

    protected function setup(): ?Cmd
    {
        return null;
    }

    /**
     * App-level key hook, consulted before the focused component.
     *
     * Returning a changed model or any command consumes the key — it will
     * NOT reach the focused component. Return UpdateResult::keep($this) to
     * pass the key on. Keys matched by applicationKeybindings() never
     * arrive here; they go to onAction().
     *
     * @return UpdateResult<static>
     */
    protected function onKey(KeyMsg $message, Rect $screen): UpdateResult
    {
        return UpdateResult::keep($this);
    }

    /**
     * Handle an application action matched by applicationKeybindings().
     *
     * Application actions are reserved before focus routing, so they work
     * consistently while an input, panel, or other component has focus.
     *
     * @return UpdateResult<static>
     */
    protected function onAction(string $action, KeyMsg $message): UpdateResult
    {
        return match ($action) {
            'quit' => $this->withCmd(Cmd::quit()),
            default => UpdateResult::keep($this),
        };
    }

    /**
     * Reserved application shortcuts, evaluated before focused components.
     *
     * Keep printable, unmodified keys out of this map when users should be
     * able to type them into an input. Widget action inheritance belongs in
     * globalKeybindings() instead.
     *
     * Overriding without a ctrl+c binding lets the app intercept ctrl+c
     * (e.g. for a confirm-before-quit dialog).
     */
    protected function applicationKeybindings(): Keybindings
    {
        return new Keybindings(['quit' => ['ctrl+q', 'ctrl+c']]);
    }

    /**
     * @return UpdateResult<static>
     */
    protected function onResize(ResizeMsg $message): UpdateResult
    {
        return UpdateResult::keep($this);
    }

    /**
     * React after bound component widgets were replaced by framework routing.
     *
     * Runs on the App instance that already carries the new widgets — use it
     * to derive dependent state, e.g. re-filter a list when a bound search
     * input changed. Returned commands are dispatched alongside the child's.
     *
     * @param list<ComponentId> $ids
     *
     * @return UpdateResult<static>
     */
    protected function onComponentsChanged(array $ids): UpdateResult
    {
        return UpdateResult::keep($this);
    }

    /**
     * @return UpdateResult<static>
     */
    protected function onMouse(MouseMsg $message, Rect $screen): UpdateResult
    {
        return UpdateResult::keep($this);
    }

    /**
     * @return UpdateResult<static>
     */
    protected function onTick(TickMsg $message): UpdateResult
    {
        return UpdateResult::keep($this);
    }

    /**
     * @return UpdateResult<static>
     */
    protected function onMessage(Message $message, Rect $screen): UpdateResult
    {
        return UpdateResult::keep($this);
    }

    /**
     * Extra keys that leave, on top of the ctrl chords applicationKeybindings()
     * already reserves. Empty by default: an unmodified letter here is honoured
     * only while no component holds focus, and an app that routes the keyboard
     * itself registers no components, so the letter would never reach what is
     * being typed into.
     */
    protected function quitBindings(): Keybindings
    {
        return new Keybindings([]);
    }

    protected function globalKeybindings(): Keybindings
    {
        return Keybindings::empty();
    }

    /**
     * Binds the property the widget already lives on, so the write-back cannot
     * name a different one. The id defaults to the property name.
     *
     * @template TBoundWidget of Interactive
     *
     * @param TBoundWidget $widget
     *
     * @return Component<TBoundWidget>
     */
    protected function bindProperty(string $property, Interactive $widget, ComponentId|string|null $id = null): Component
    {
        return Component::bind(
            $id ?? $property,
            $widget,
            static fn (self $app, Interactive $updated): self => $app->cloneWith(...[$property => $updated]),
        );
    }

    /**
     * @return UpdateResult<static>
     */
    protected function withCmd(Cmd $command): UpdateResult
    {
        return UpdateResult::changed($this, $command);
    }

    /**
     * Which keys move the focus from one bound component to the next. Override
     * to put focus cycling somewhere else; a form that types tab characters is
     * the usual reason.
     */
    protected function focusCycleBindings(): Keybindings
    {
        return new Keybindings([
            Actions::FOCUS_CYCLE => ['tab'],
            Actions::FOCUS_PREV_CYCLE => ['shift+tab'],
        ]);
    }

    /**
     * The tree names the component the keys belong to. Its own flag does not.
     *
     * @param list<Component<Interactive>> $focusables
     *
     * @return list<Component<Interactive>>
     */
    private static function holdingTheKeyboard(array $focusables, ?ComponentId $focused): array
    {
        if ($focused === null) {
            return $focusables;
        }

        foreach ($focusables as $component) {
            if ($component->id->equals($focused)) {
                return [$component];
            }
        }

        return $focusables;
    }

    /**
     * @param list<Component<Interactive>> $components
     *
     * @return UpdateResult<static>
     */
    private function handleLayerOne(Message $message, Rect $screenRect, array $components): UpdateResult
    {
        if ($message instanceof KeyMsg) {
            if ($this->resolvedQuitBindings()->matches($message, 'quit')) {
                return new UpdateResult($this, Cmd::quit());
            }

            return $this->onKey($message, $screenRect);
        }

        if ($message instanceof MouseMsg) {
            return $this->delegateMouse($message, $screenRect, $components);
        }

        if ($message instanceof TickMsg) {
            return $this->broadcastAfter($this->onTick($message), $message, $screenRect);
        }

        if ($message instanceof FloatMouseMsg) {
            return $this->broadcastAfter($this->onMessage($message, $screenRect), $message, $screenRect);
        }

        return $this->onMessage($message, $screenRect);
    }

    /**
     * @param list<Component<Interactive>> $focusables
     *
     * @return UpdateResult<static>
     */
    private function handleLayerTwo(Message $message, Rect $screenRect, array $focusables): UpdateResult
    {
        if ($message instanceof KeyMsg) {
            $bindings = $this->focusCycleBindings();

            if ($bindings->matches($message, Actions::FOCUS_PREV_CYCLE)) {
                return $this->handleTab($message, $screenRect, $focusables, true);
            }

            if ($bindings->matches($message, Actions::FOCUS_CYCLE)) {
                return $this->handleTab($message, $screenRect, $focusables, $message->shift);
            }
        }

        if ($message instanceof KeyMsg) {
            return $this->handleLayerTwoKey($message, $screenRect, $focusables);
        }

        if ($message instanceof MouseMsg) {
            return $this->delegateMouse($message, $screenRect, $this->componentRegistry()->all());
        }

        if ($message instanceof TickMsg) {
            return $this->broadcastAfter($this->onTick($message), $message, $screenRect);
        }

        if ($message instanceof FloatMouseMsg) {
            return $this->broadcastAfter($this->onMessage($message, $screenRect), $message, $screenRect);
        }

        return $this->onMessage($message, $screenRect);
    }

    /**
     * @param list<Component<Interactive>> $focusables
     *
     * @return UpdateResult<static>
     */
    private function handleTab(KeyMsg $message, Rect $screenRect, array $focusables, bool $reverse): UpdateResult
    {
        $focused = $this->findFocusedComponent($focusables);

        if ($focused !== null) {
            /** @var Focusable $widget */
            $widget = $focused->widget;
            $rect = $this->findComponentRects($screenRect, $focusables)[$focused->id->value];
            $dispatch = $this->childDispatcher()->dispatchOne($widget, $message, $rect);

            if ($dispatch->changed || $dispatch->command !== null) {
                [$current, $hookCommand] = $dispatch->changed
                    ? $this->applyComponentUpdatesAndNotify($this, [[$focused, $dispatch->child]])
                    : [$this, null];

                return new UpdateResult(
                    $current,
                    self::combineCommands($dispatch->command, $hookCommand),
                );
            }
        }

        return $this->cycleFocus($focusables, $reverse);
    }

    /**
     * @param list<Component<Interactive>> $focusables
     *
     * @return UpdateResult<static>
     */
    private function handleLayerTwoKey(KeyMsg $message, Rect $screenRect, array $focusables): UpdateResult
    {
        if ($this->findFocusedComponent($focusables) === null
            && $this->resolvedQuitBindings()->matches($message, 'quit')
        ) {
            return new UpdateResult($this, Cmd::quit());
        }

        $appResult = $this->onKey($message, $screenRect);

        if ($appResult->model !== $this || $appResult->command !== null) {
            return $appResult;
        }

        return $this->delegateToFocused($message, $screenRect, $focusables);
    }

    /**
     * @param list<Component<Interactive>> $focusables
     *
     * @return Component<Interactive>|null
     */
    private function findFocusedComponent(array $focusables): ?Component
    {
        $index = $this->findFocusedIndex($focusables);

        return $index === null ? null : $focusables[$index];
    }

    /**
     * Derived from the focusables rather than stored, so the context cannot
     * disagree with the widget the keys are routed to while both mechanisms
     * are live.
     */
    private function focusState(): FocusState
    {
        $focusables = $this->componentRegistry()->focusables();
        $focused = $this->findFocusedIndex($focusables);

        return new FocusState(null === $focused ? null : $focusables[$focused]->id);
    }

    /**
     * @param list<Component<Interactive>> $focusables
     */
    private function findFocusedIndex(array $focusables): ?int
    {
        foreach ($focusables as $index => $component) {
            /** @var Focusable $widget */
            $widget = $component->widget;
            if ($widget->isFocused()) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param list<Component<Interactive>> $focusables
     *
     * @return UpdateResult<static>
     */
    private function cycleFocus(array $focusables, bool $reverse): UpdateResult
    {
        $tree = new FocusTree(count($focusables), $this->findFocusedIndex($focusables));
        $nextIndex = ($reverse ? $tree->prev() : $tree->next())->active();
        $updates = [];
        foreach ($focusables as $index => $component) {
            /** @var Focusable $widget */
            $widget = $component->widget;
            $shouldFocus = $index === $nextIndex;

            if ($widget->isFocused() !== $shouldFocus) {
                $updates[] = [$component, $widget->withFocus($shouldFocus)];
            }
        }

        [$next, $hookCommand] = $this->applyComponentUpdatesAndNotify($this, $updates);

        return new UpdateResult($next, $hookCommand);
    }

    /**
     * @param list<Component<Interactive>> $components
     *
     * @return array<string, Rect>
     */
    private function findComponentRects(Rect $screenRect, array $components): array
    {
        $rectMap = LayoutSnapshot::rectsFor($this, $screenRect, $this->viewWidget(...));
        $result = [];

        foreach ($components as $component) {
            $key = $component->id->value;
            $result[$key] = $rectMap->offsetExists($component)
                ? $rectMap[$component]
                : throw new LogicException("Missing rect for component key '$key'");
        }

        return $result;
    }

    /**
     * @param list<Component<Interactive>> $focusables
     *
     * @return UpdateResult<static>
     */
    private function delegateToFocused(Message $message, Rect $screenRect, array $focusables): UpdateResult
    {
        $focused = $this->findFocusedComponent($focusables);

        if ($focused === null) {
            return new UpdateResult($this);
        }

        /** @var Focusable $widget */
        $widget = $focused->widget;
        $rect = $this->findComponentRects($screenRect, $focusables)[$focused->id->value];
        $dispatch = $this->childDispatcher()->dispatchOne($widget, $message, $rect);

        if ($dispatch->changed) {
            [$current, $hookCommand] = $this->applyComponentUpdatesAndNotify($this, [[$focused, $dispatch->child]]);

            return new UpdateResult(
                $current,
                self::combineCommands($dispatch->command, $hookCommand),
            );
        }

        return new UpdateResult($this, $dispatch->command);
    }

    /**
     * @param list<Component<Interactive>> $components
     *
     * @return UpdateResult<static>
     */
    private function delegateMouse(MouseMsg $message, Rect $screenRect, array $components): UpdateResult
    {
        $appResult = $this->onMouse($message, $screenRect);
        $current = $this->appModel($appResult);

        if ($current !== $this) {
            $components = $current->componentRegistry()->all();
        }
        $rects = $current->findComponentRects($screenRect, $components);
        [$updates, $childCommand] = $current->dispatchComponents(
            $components,
            $rects,
            $message,
            $current->childDispatcher(),
        );

        // Where the pointer landed is a question about the screen, so every bound
        // widget is asked. Only one that can hold focus has its focus moved.
        if ($message->isClick()) {
            foreach ($components as $component) {
                $shouldFocus = $message->inRect($rects[$component->id->value]);
                $existing = $updates[$component->id->value][1] ?? $component->widget;

                if ($existing instanceof Focusable && $existing->isFocused() !== $shouldFocus) {
                    $updates[$component->id->value] = [$component, $existing->withFocus($shouldFocus)];
                }
            }
        }

        [$next, $hookCommand] = $this->applyComponentUpdatesAndNotify($current, $updates);

        return new UpdateResult(
            $next,
            self::combineCommands($appResult->command, $childCommand, $hookCommand),
        );
    }

    /**
     * Every component sees the message, whatever the hook returned: hosts with
     * no components at all would otherwise never see it.
     *
     * @param UpdateResult<static> $hookResult
     *
     * @return UpdateResult<static>
     */
    private function broadcastAfter(UpdateResult $hookResult, Message $message, Rect $screenRect): UpdateResult
    {
        /** @var static $current */
        $current = $hookResult->model;

        return $this->broadcastTo($hookResult, $message, $screenRect, $current->componentRegistry()->all());
    }

    /**
     * @param list<Component<Interactive>> $components
     * @param UpdateResult<static>         $appResult
     *
     * @return UpdateResult<static>
     */
    private function broadcastTo(UpdateResult $appResult, Message $message, Rect $screenRect, array $components): UpdateResult
    {
        if ($components === []) {
            return $appResult;
        }

        $current = $this->appModel($appResult);
        $rects = $current->findComponentRects($screenRect, $components);
        [$updates, $childCommand] = $current->dispatchComponents(
            $components,
            $rects,
            $message,
            $current->childDispatcher(),
        );

        [$next, $hookCommand] = $this->applyComponentUpdatesAndNotify($current, $updates);

        return new UpdateResult(
            $next,
            self::combineCommands($appResult->command, $childCommand, $hookCommand),
        );
    }

    /**
     * @param list<Component<Interactive>> $components
     * @param array<string, Rect>          $rects
     *
     * @return array{array<string, array{Component<Interactive>, Interactive}>, ?Cmd}
     */
    private function dispatchComponents(
        array $components,
        array $rects,
        Message $message,
        ChildDispatcher $dispatcher,
    ): array {
        $widgets = [];
        $componentsById = [];
        foreach ($components as $component) {
            $key = $component->id->value;
            $widgets[$key] = $component->widget;
            $componentsById[$key] = $component;
        }

        $batch = $dispatcher->dispatchMap($widgets, $rects, $message);
        $updates = [];
        foreach ($batch->overrides as $key => $widget) {
            $updates[$key] = [$componentsById[$key], $widget];
        }

        return [$updates, $batch->command];
    }

    private static function combineCommands(?Cmd ...$commands): ?Cmd
    {
        $present = [];
        foreach ($commands as $command) {
            if ($command !== null) {
                $present[] = $command;
            }
        }

        return match (count($present)) {
            0 => null,
            1 => $present[0],
            default => Cmd::batch(...$present),
        };
    }

    private function componentRegistry(): ComponentRegistry
    {
        return ComponentRegistry::for($this, $this->viewWidget(...));
    }

    private function viewWidget(): Widget
    {
        return ViewSnapshot::for($this, $this->view(...));
    }

    private function childDispatcher(): ChildDispatcher
    {
        /** @var WeakMap<object, ChildDispatcher>|null $dispatchers */
        static $dispatchers = null;
        $dispatchers ??= new WeakMap();

        return $dispatchers[$this] ??= new ChildDispatcher($this->globalKeybindings());
    }

    private function resolvedQuitBindings(): Keybindings
    {
        /** @var WeakMap<object, Keybindings>|null $bindings */
        static $bindings = null;
        $bindings ??= new WeakMap();

        return $bindings[$this] ??= $this->quitBindings();
    }

    private function matchedApplicationAction(KeyMsg $message): ?string
    {
        /** @var WeakMap<object, Keybindings>|null $bindings */
        static $bindings = null;
        $bindings ??= new WeakMap();
        $applicationBindings = $bindings[$this] ??= $this->applicationKeybindings();
        foreach ($applicationBindings->actions() as $action) {
            if ($applicationBindings->matches($message, $action)) {
                return $action;
            }
        }

        return null;
    }

    /**
     * @param UpdateResult<Reducer> $result
     *
     * @return static
     */
    private function appModel(UpdateResult $result): self
    {
        if (!$result->model instanceof self) {
            throw new LogicException(sprintf('App update must return an App model, got %s', get_debug_type($result->model)));
        }

        /** @var static $model */
        $model = $result->model;

        return $model;
    }

    /**
     * Apply component replacements, then let the app derive dependent state.
     *
     * @param static                                                       $app
     * @param array<array-key, array{Component<Interactive>, Interactive}> $updates
     *
     * @return array{static, ?Cmd}
     */
    private function applyComponentUpdatesAndNotify(self $app, array $updates): array
    {
        if ($updates === []) {
            return [$app, null];
        }

        $updated = $this->applyComponentUpdates($app, $updates);

        $ids = [];
        foreach ($updates as [$original]) {
            $ids[] = $original->id;
        }

        $result = $updated->onComponentsChanged($ids);

        return [$updated->appModel($result), $result->command];
    }

    /**
     * @param static                                                       $app
     * @param array<array-key, array{Component<Interactive>, Interactive}> $updates
     *
     * @return static
     */
    private function applyComponentUpdates(self $app, array $updates): self
    {
        foreach ($updates as [$original, $widget]) {
            /** @var static $app */
            $app = $original->replaceIn($app, $widget);
        }

        $registry = $app->componentRegistry();
        foreach ($updates as [$original, $widget]) {
            $rebound = $registry->get($original->id);
            if ($rebound === null) {
                throw new LogicException(sprintf('Component "%s" disappeared during an update', $original->id->value));
            }

            if ($rebound->widget !== $widget) {
                throw new LogicException(sprintf('Component "%s" stored its update on another property', $original->id->value));
            }
        }

        return $app;
    }
}
