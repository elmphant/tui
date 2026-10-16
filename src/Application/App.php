<?php

declare(strict_types=1);

namespace Elmphant\Tui\Application;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\FocusState;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\PointerFocus;
use Elmphant\Tui\Core\Reducer;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Input\KeybindingsAware;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\FloatMouseMsg;
use Elmphant\Tui\Message\FocusMsg;
use Elmphant\Tui\Message\KeyEventType;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Message\PasteMsg;
use Elmphant\Tui\Message\ResizeMsg;
use Elmphant\Tui\Message\TickMsg;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderPass;
use Elmphant\Tui\Runtime\RoutesFocus;
use Elmphant\Tui\Support\Cloneable;
use Elmphant\Tui\Widget\Dispatch\ChildDispatcher;
use Elmphant\Tui\Widget\Focus\CapturesKeys;
use Elmphant\Tui\Widget\Focusable;
use Elmphant\Tui\Widget\FollowsTheKeyboard;
use Elmphant\Tui\Widget\GraftsItsChildren;
use Elmphant\Tui\Widget\Interactive;
use Elmphant\Tui\Widget\Plan\LayoutSnapshot;
use Elmphant\Tui\Widget\PublishesHints;
use Elmphant\Tui\Widget\Widget;
use Elmphant\Tui\Widget\WidgetContainer;
use LogicException;
use WeakMap;

abstract readonly class App implements RoutesFocus
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

    final public function render(Frame $frame, RenderPass $pass): void
    {
        $area = $frame->area();
        $focused = $pass->focus->focusedComponent();

        $pass->focus->provideHints($this->hintsOfFocused($focused));
        $pass->focus->provideStopPublishers($this->componentRegistry()->stopPublishers());
        $pass->focus->provideEnclosure($focused === null ? [] : array_map(
            static fn (Component $component): string => $component->id->value,
            $this->enclosing($focused),
        ));

        $root = $this->viewWidget();
        $plan = LayoutSnapshot::existingPlanFor($this, $area);
        $pass->floats->drawOver(
            $frame,
            static fn () => $pass->layout->during($plan, static fn () => $root->render($frame, $area, $pass->context)),
            $pass->context,
        );
    }

    final public function update(Message $message, Rect $screenRect, FocusState $focus = new FocusState()): UpdateResult
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

        return $this->handleLayerTwo($message, $screenRect, $focusables, $focus->focused());
    }

    /**
     * @return list<ComponentId>
     */
    final public function focusableIds(FocusState $focus = new FocusState()): array
    {
        return array_map(
            static fn (Component $component): ComponentId => $component->id,
            $this->componentRegistry()->ring($focus->focused()),
        );
    }

    /**
     * @return list<ComponentId>
     */
    final public function reachableIds(): array
    {
        return array_map(
            static fn (Component $component): ComponentId => $component->id,
            $this->componentRegistry()->focusables(),
        );
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
            ->hints([$description => 'quit']);
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
     * Keep printable, unmodified keys out of this map when the reader should be
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
    protected function onTick(TickMsg $message, Rect $screen): UpdateResult
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
            Actions::FOCUS_PREVIOUS_CYCLE => ['shift+tab'],
        ]);
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

        return $this->handleUnrouted($message, $screenRect);
    }

    /**
     * @param list<Component<Interactive>> $focusables
     *
     * @return UpdateResult<static>
     */
    private function handleLayerTwo(Message $message, Rect $screenRect, array $focusables, ?ComponentId $holder): UpdateResult
    {
        if ($message instanceof KeyMsg) {
            $bindings = $this->focusCycleBindings();

            if ($bindings->matches($message, Actions::FOCUS_PREVIOUS_CYCLE)) {
                return $this->handleTab($message, $screenRect, $focusables, $holder, true);
            }

            if ($bindings->matches($message, Actions::FOCUS_CYCLE)) {
                return $this->handleTab($message, $screenRect, $focusables, $holder, $message->shift);
            }

            return $this->handleLayerTwoKey($message, $screenRect, $focusables, $holder);
        }

        if ($message instanceof MouseMsg) {
            return $this->delegateMouse($message, $screenRect, $this->componentRegistry()->all(), $holder);
        }

        if ($message instanceof FocusMsg) {
            return $this->delegateArrival($message, $screenRect, $focusables, $holder);
        }

        // A paste is typing, so it goes where the typing goes. Handed to every
        // component it would fill every field of a form at once.
        if ($message instanceof PasteMsg) {
            return $this->delegateToFocused($message, $screenRect, $focusables, $holder);
        }

        return $this->handleUnrouted($message, $screenRect);
    }

    /**
     * The containers above hear where below them the keyboard landed; the
     * component itself hears the message.
     *
     * @param list<Component<Interactive>> $focusables
     *
     * @return UpdateResult<static>
     */
    private function delegateArrival(FocusMsg $message, Rect $screenRect, array $focusables, ?ComponentId $holder): UpdateResult
    {
        $focused = $this->findFocusedComponent($focusables, $holder);

        if ($focused === null) {
            return new UpdateResult($this);
        }

        $updates = $this->keyboardAnnouncements($focused);
        $command = null;
        $rect = $this->findComponentRects($screenRect, $focusables)[$focused->id->value] ?? null;

        if ($focused->writesBack() && $rect !== null) {
            /** @var Interactive $widget */
            $widget = $focused->widget;
            $dispatch = $this->childDispatcher()->dispatchOne($widget, $message, $rect);

            if ($dispatch->changed) {
                $updates[] = [$focused, $dispatch->child];
            }

            $command = $focused->addressed($dispatch->command);
        }

        [$current, $hookCommand] = $this->applyComponentUpdatesAndNotify($this, $updates);

        return new UpdateResult($current, self::combineCommands($command, $hookCommand));
    }

    /**
     * @param Component<Interactive> $focused
     *
     * @return array<string, array{Component<Interactive>, Interactive}>
     */
    private function keyboardAnnouncements(Component $focused): array
    {
        $updates = [];

        foreach ($this->enclosing($focused->id) as $ancestor) {
            /** @var Interactive $container */
            $container = $ancestor->widget;

            if (!$ancestor->writesBack() || !$container instanceof FollowsTheKeyboard || !$container instanceof WidgetContainer) {
                continue;
            }

            $child = $focused->id->isUnder($ancestor->id)
                ? $focused->id->pathBelow($ancestor->id)[0]
                : self::childHolding($container, $focused->id);

            if ($child === null) {
                continue;
            }

            $told = $container->withKeyboardAt($child);

            if ($told !== $container) {
                $updates[$ancestor->id->value] = [$ancestor, $told];
            }
        }

        return $updates;
    }

    private static function childHolding(WidgetContainer $container, ComponentId $id): ?int
    {
        $index = 0;

        foreach ($container->children() as $child) {
            if (self::holdsComponent($child, $id)) {
                return $index;
            }

            ++$index;
        }

        return null;
    }

    /**
     * What neither layer routes by hand. A tick and a click on a float reach every
     * component once the app's hook has had it; anything else is the app's alone.
     * Both layers answer these the same way, so they answer them in one place.
     *
     * @return UpdateResult<static>
     */
    private function handleUnrouted(Message $message, Rect $screenRect): UpdateResult
    {
        if ($message instanceof TickMsg) {
            return $this->broadcastAfter($this->onTick($message, $screenRect), $message, $screenRect);
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
    private function handleTab(KeyMsg $message, Rect $screenRect, array $focusables, ?ComponentId $holder, bool $reverse): UpdateResult
    {
        $focused = $this->findFocusedComponent($focusables, $holder);

        if ($focused !== null && $focused->writesBack()) {
            /** @var Focusable $widget */
            $widget = $focused->widget;
            $rect = $this->findComponentRects($screenRect, $focusables)[$focused->id->value] ?? null;

            if ($rect === null) {
                return new UpdateResult($this, $reverse ? Cmd::focusPrevious() : Cmd::focusNext());
            }

            $dispatch = $this->childDispatcher()->dispatchOne($widget, $message, $rect);

            if ($dispatch->changed || $dispatch->command !== null) {
                [$current, $hookCommand] = $dispatch->changed
                    ? $this->applyComponentUpdatesAndNotify($this, $this->focusedUpdate($focused, $dispatch->child))
                    : [$this, null];

                return new UpdateResult(
                    $current,
                    self::combineCommands($focused->addressed($dispatch->command), $hookCommand),
                );
            }
        }

        return new UpdateResult($this, $reverse ? Cmd::focusPrevious() : Cmd::focusNext());
    }

    /**
     * @param list<Component<Interactive>> $focusables
     *
     * @return UpdateResult<static>
     */
    private function handleLayerTwoKey(KeyMsg $message, Rect $screenRect, array $focusables, ?ComponentId $holder): UpdateResult
    {
        if ($this->findFocusedComponent($focusables, $holder) === null
            && $this->resolvedQuitBindings()->matches($message, 'quit')
        ) {
            return new UpdateResult($this, Cmd::quit());
        }

        $appResult = $this->onKey($message, $screenRect);

        if ($appResult->model !== $this || $appResult->command !== null) {
            return $appResult;
        }

        return $this->delegateToFocused($message, $screenRect, $focusables, $holder);
    }

    /**
     * @param list<Component<Interactive>> $focusables
     *
     * @return Component<Interactive>|null
     */
    private function findFocusedComponent(array $focusables, ?ComponentId $holder): ?Component
    {
        if ($holder === null) {
            return null;
        }

        foreach ($focusables as $component) {
            if ($component->id->equals($holder)) {
                return $component;
            }
        }

        return null;
    }

    /**
     * @param list<Component<Interactive>> $components
     *
     * @return array<string, Rect>
     */
    private function findComponentRects(Rect $screenRect, array $components, bool $visible = false): array
    {
        $plan = LayoutSnapshot::planFor($this, $screenRect, $this->viewWidget(...));
        $rectMap = $visible ? $plan->visibleRects() : $plan->rects();
        $result = [];

        // A component derived from a child is not itself in the tree; its widget is.
        foreach ($components as $component) {
            foreach ([$component, $component->widget] as $placed) {
                if ($rectMap->contains($placed)) {
                    $result[$component->id->value] = $rectMap[$placed];

                    break;
                }
            }
        }

        return $result;
    }

    /**
     * @param list<Component<Interactive>> $focusables
     *
     * @return UpdateResult<static>
     */
    private function delegateToFocused(Message $message, Rect $screenRect, array $focusables, ?ComponentId $holder): UpdateResult
    {
        $focused = $this->findFocusedComponent($focusables, $holder);

        if ($focused === null || !$focused->writesBack()) {
            return new UpdateResult($this);
        }

        /** @var Focusable $widget */
        $widget = $focused->widget;
        $rect = $this->findComponentRects($screenRect, $focusables)[$focused->id->value] ?? null;

        if ($rect === null) {
            return $this->offeredToWhatEncloses($message, $screenRect, $focused);
        }

        $dispatch = $this->childDispatcher()->dispatchOne($widget, $message, $rect);

        if ($dispatch->changed) {
            [$current, $hookCommand] = $this->applyComponentUpdatesAndNotify($this, $this->focusedUpdate($focused, $dispatch->child));

            return new UpdateResult(
                $current,
                self::combineCommands($focused->addressed($dispatch->command), $hookCommand),
            );
        }

        if ($dispatch->command !== null) {
            return new UpdateResult($this, $focused->addressed($dispatch->command));
        }

        return $this->offeredToWhatEncloses($message, $screenRect, $focused);
    }

    /**
     * Input the focused component answered brings it back into view where the
     * reader had scrolled away from it, so the containers hear the keyboard again.
     *
     * @param Component<Interactive> $focused
     *
     * @return list<array{Component<Interactive>, Interactive}>
     */
    private function focusedUpdate(Component $focused, Interactive $child): array
    {
        return [...array_values($this->keyboardAnnouncements($focused)), [$focused, $child]];
    }

    /**
     * A key the focused component leaves alone belongs to whatever it sits inside:
     * once a field takes the keyboard, the pane around it is no longer where keys
     * arrive, and nothing could scroll a form while a field is being filled in.
     *
     * @param Component<Interactive> $focused
     *
     * @return UpdateResult<static>
     */
    private function offeredToWhatEncloses(Message $message, Rect $screenRect, Component $focused): UpdateResult
    {
        if ($focused->widget instanceof CapturesKeys && $focused->widget->capturesKeys()) {
            return new UpdateResult($this);
        }

        $enclosing = array_values(array_filter(
            $this->enclosing($focused->id),
            static fn (Component $candidate): bool => $candidate->writesBack(),
        ));
        $rects = $this->findComponentRects($screenRect, $enclosing);

        // Nearest first: collection is pre-order, so what contains the rest is listed
        // before it and the innermost is the last match.
        foreach (array_reverse($enclosing) as $candidate) {
            $dispatch = $this->childDispatcher()->dispatchOne(
                $candidate->widget,
                $message,
                $rects[$candidate->id->value] ?? $screenRect,
            );

            if ($dispatch->changed) {
                [$current, $hookCommand] = $this->applyComponentUpdatesAndNotify($this, [[$candidate, $dispatch->child]]);

                return new UpdateResult(
                    $current,
                    self::combineCommands($candidate->addressed($dispatch->command), $hookCommand),
                );
            }

            if ($dispatch->command !== null) {
                return new UpdateResult($this, $candidate->addressed($dispatch->command));
            }
        }

        return new UpdateResult($this);
    }

    /**
     * The components whose subtree holds $id, outermost first.
     *
     * @return list<Component<Interactive>>
     */
    private function enclosing(ComponentId $id): array
    {
        return array_values(array_filter(
            $this->componentRegistry()->all(),
            static fn (Component $candidate): bool => !$candidate->id->equals($id) && self::holdsComponent($candidate, $id),
        ));
    }

    private static function holdsComponent(Widget $widget, ComponentId $id): bool
    {
        if ($widget instanceof Component && ($widget->id->equals($id) || $id->isUnder($widget->id))) {
            return true;
        }

        if (!$widget instanceof WidgetContainer) {
            return false;
        }

        foreach ($widget->children() as $child) {
            if (self::holdsComponent($child, $id)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<Component<Interactive>> $components
     *
     * @return UpdateResult<static>
     */
    private function delegateMouse(
        MouseMsg $message,
        Rect $screenRect,
        array $components,
        ?ComponentId $holder = null,
    ): UpdateResult {
        $appResult = $this->onMouse($message, $screenRect);
        $current = $this->appModel($appResult);

        if ($current !== $this) {
            $components = $current->componentRegistry()->all();
        }
        $rects = $current->findComponentRects($screenRect, $components);
        $visible = $current->findComponentRects($screenRect, $components, visible: true);

        // A child its pane scrolled away still has a rect under whatever is drawn there now.
        $components = array_values(array_filter(
            $components,
            static fn (Component $component): bool => !isset($rects[$component->id->value])
                || !$message->inRect($rects[$component->id->value])
                || (isset($visible[$component->id->value]) && $message->inRect($visible[$component->id->value])),
        ));
        [$updates, $childCommand] = $current->dispatchComponents(
            $components,
            $rects,
            $message,
            $current->childDispatcher(),
            $holder,
        );

        // Where the pointer landed is a question about the screen, so every bound
        // widget the keyboard can reach is asked. Routing says where the keyboard
        // goes and never edits a flag to do it: writing one back would run the
        // closure of a component the pointer never touched, putting its untouched
        // state on top of whatever the one that was touched just changed.
        $landedOn = null;
        if ($message->isClick() || $message->isWheel()) {
            foreach ($current->componentRegistry()->focusables() as $component) {
                $rect = $visible[$component->id->value] ?? null;

                if ($rect === null || !$message->inRect($rect)) {
                    continue;
                }

                // A wheel is reading, so the keyboard follows it only to what scrolled
                // with it; a host-driven component cannot be asked and is trusted.
                if ($message->isWheel() && $component->writesBack() && !isset($updates[$component->id->value])) {
                    continue;
                }

                $landedOn = $component->id;
            }
        }

        [$next, $hookCommand] = $this->applyComponentUpdatesAndNotify($current, $updates);
        $answered = self::combineCommands($appResult->command, $childCommand, $hookCommand);

        return new UpdateResult(
            $next,
            self::combineCommands(
                $answered,
                PointerFocus::alreadyMoved($answered) ? null : PointerFocus::after($landedOn),
            ),
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
        ?ComponentId $holder = null,
    ): array {
        $updates = [];
        $commands = [];

        // One at a time rather than as a batch: a command is addressed by the
        // component that answered, and a batch has already combined them.
        foreach ($components as $component) {
            if (!$component->writesBack()) {
                continue;
            }

            /** @var Interactive $widget */
            $widget = $component->widget;
            $key = $component->id->value;
            $rect = $rects[$key] ?? null;

            if ($rect === null) {
                continue;
            }

            $addressed = $message instanceof MouseMsg
                ? $message->withKeyboardHere($holder?->equals($component->id) ?? false)
                : $message;

            $dispatch = $dispatcher->dispatchOne($widget, $addressed, $rect);

            if ($dispatch->changed) {
                $updates[$key] = [$component, $dispatch->child];
            }

            $commands[] = $component->addressed($dispatch->command);
        }

        return [$updates, self::combineCommands(...$commands)];
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

    /**
     * @return array<string, string>
     */
    private function hintsOfFocused(?ComponentId $focused): array
    {
        if ($focused === null) {
            return [];
        }

        $widget = $this->componentRegistry()->get($focused)?->widget;

        // The stored widget is the authored one: routing hands the globals over on
        // the way in and unwraps again, so nothing kept has them.
        if ($widget instanceof KeybindingsAware) {
            $widget = $widget->withGlobalKeybindings($this->globalKeybindings());
        }

        return $widget instanceof PublishesHints ? $widget->hints() : [];
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
        static $dispatchers = null;
        $dispatchers ??= new WeakMap();

        /**
         * @var WeakMap<object, ChildDispatcher> $dispatchers
         */
        return $dispatchers[$this] ??= new ChildDispatcher($this->globalKeybindings());
    }

    private function resolvedQuitBindings(): Keybindings
    {
        static $bindings = null;
        $bindings ??= new WeakMap();

        /**
         * @var WeakMap<object, Keybindings> $bindings
         */
        return $bindings[$this] ??= $this->quitBindings();
    }

    private function matchedApplicationAction(KeyMsg $message): ?string
    {
        static $bindings = null;
        $bindings ??= new WeakMap();
        /**
         * @var WeakMap<object, Keybindings> $bindings
         */
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
        $updates = self::withoutLetGo($updates);
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
        foreach (self::foldedIntoAncestors($updates) as [$original, $widget]) {
            // A mounted component writes back through the sub-model the tree held
            // when it was collected, so it is looked up in the app as it stands or a
            // second write restores the sub-model the first one changed.
            $current = $app->componentRegistry()->get($original->id) ?? $original;
            $replaced = $current->replaceIn($app, $widget);
            ComponentWriteBack::verifyStored($current, $widget, $app, $replaced);
            /** @var static $app */
            $app = $replaced;
        }

        ComponentWriteBack::verify($app->componentRegistry(), $updates);

        return $app;
    }

    /**
     * A grafted child writes back through its container as it was when the tree
     * was collected, so beside the container's own answer one of the two is lost.
     *
     * @param array<array-key, array{Component<Interactive>, Interactive}> $updates
     *
     * @return list<array{Component<Interactive>, Interactive}>
     */
    private static function foldedIntoAncestors(array $updates): array
    {
        $byId = [];

        foreach ($updates as [$component, $widget]) {
            $byId[$component->id->value] = [$component, $widget];
        }

        foreach ($byId as $id => [$component, $widget]) {
            $ancestor = self::nearestAnswering($component->id, $byId);

            if ($ancestor === null) {
                continue;
            }

            [$ancestorComponent, $ancestorWidget] = $byId[$ancestor];
            $path = $component->id->pathBelow($ancestorComponent->id);
            $byId[$ancestor] = [$ancestorComponent, self::grafted($ancestorWidget, $path, $widget)];
            unset($byId[$id]);
        }

        return array_values($byId);
    }

    /**
     * @param array<string, array{Component<Interactive>, Interactive}> $byId
     */
    private static function nearestAnswering(ComponentId $id, array $byId): ?string
    {
        $nearest = null;

        foreach ($byId as $candidate => [$component]) {
            if ($id->isUnder($component->id) && ($nearest === null || strlen($candidate) > strlen($nearest))) {
                $nearest = $candidate;
            }
        }

        return $nearest;
    }

    /**
     * A container's own answer may let go of a child that answered too (a tab
     * switched pages); grafting into whatever sits there now would overwrite a
     * stranger, so the container's answer wins.
     *
     * @param array<array-key, array{Component<Interactive>, Interactive}> $updates
     *
     * @return array<array-key, array{Component<Interactive>, Interactive}>
     */
    private static function withoutLetGo(array $updates): array
    {
        $byId = [];
        foreach ($updates as [$component, $widget]) {
            $byId[$component->id->value] = [$component, $widget];
        }

        $letGo = [];
        foreach ($byId as [$component]) {
            $ancestor = self::nearestAnswering($component->id, $byId);
            if ($ancestor === null) {
                continue;
            }

            [$ancestorComponent, $ancestorWidget] = $byId[$ancestor];
            if (self::heldAt($ancestorWidget, $component->id->pathBelow($ancestorComponent->id)) !== $component->widget) {
                $letGo[] = $component->id;
            }
        }

        return array_filter($updates, static function (array $update) use ($letGo): bool {
            foreach ($letGo as $gone) {
                if ($update[0]->id->equals($gone) || $update[0]->id->isUnder($gone)) {
                    return false;
                }
            }

            return true;
        });
    }

    /**
     * @param list<int> $path
     */
    private static function heldAt(Widget $container, array $path): ?Widget
    {
        foreach ($path as $index) {
            if (!$container instanceof GraftsItsChildren) {
                return null;
            }

            $container = [...$container->children()][$index] ?? null;
            if ($container === null) {
                return null;
            }
        }

        return $container;
    }

    /**
     * @param non-empty-list<int> $path
     */
    private static function grafted(Interactive $container, array $path, Interactive $child): Interactive
    {
        if (!$container instanceof GraftsItsChildren) {
            throw new LogicException(sprintf('%s published a child but does not graft one back', $container::class));
        }

        $index = $path[0];
        $rest = array_slice($path, 1);

        if ($rest === []) {
            return $container->withChildAt($index, $child);
        }

        $inner = [...$container->children()][$index] ?? null;

        if (!$inner instanceof Interactive) {
            throw new LogicException(sprintf('%s has no interactive child at %d to graft into', $container::class, $index));
        }

        return $container->withChildAt($index, self::grafted($inner, $rest, $child));
    }
}
