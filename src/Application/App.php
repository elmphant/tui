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
use Elmphant\Tui\Input\KeyPattern;
use Elmphant\Tui\Input\TakesText;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\FocusMsg;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Message\PasteMsg;
use Elmphant\Tui\Message\ResizeMsg;
use Elmphant\Tui\Message\TickMsg;
use Elmphant\Tui\Rendering\Context\FloatMouseMsg;
use Elmphant\Tui\Rendering\Context\RenderPass;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Runtime\BindsChords;
use Elmphant\Tui\Runtime\ChoosesItsTheme;
use Elmphant\Tui\Runtime\DescribesKeys;
use Elmphant\Tui\Runtime\RoutesFocus;
use Elmphant\Tui\Support\Cloneable;
use Elmphant\Tui\Widget\Committing;
use Elmphant\Tui\Widget\Dispatch\ChildDispatcher;
use Elmphant\Tui\Widget\Focus\CapturesKeys;
use Elmphant\Tui\Widget\Focus\FollowsTheKeyboard;
use Elmphant\Tui\Widget\Focusable;
use Elmphant\Tui\Widget\Interactive;
use Elmphant\Tui\Widget\Plan\LayoutPlan;
use Elmphant\Tui\Widget\Plan\LayoutSnapshot;
use Elmphant\Tui\Widget\PublishesHints;
use Elmphant\Tui\Widget\Widget;
use Elmphant\Tui\Widget\WidgetContainer;
use LogicException;
use WeakMap;

abstract readonly class App implements RoutesFocus, ChoosesItsTheme, BindsChords, DescribesKeys
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
        return Cmd::combine($this->setup(), ...array_map(
            static fn (Mount $mount): ?Cmd => $mount->model instanceof Initializes ? $mount->addressed($mount->model->init()) : null,
            $this->allMounts(),
        ));
    }

    final public function render(Frame $frame, RenderPass $pass): void
    {
        $area = $frame->area();
        $focused = $pass->focus->focusedComponent();

        $pass->focus->provideHints($this->hintsOfFocused($focused));
        $pass->focus->provideTakesText($this->takesText($focused));
        $pass->focus->provideStopPublishers($this->componentRegistry()->stopPublishers());
        $pass->focus->provideEnclosure($focused === null ? [] : array_map(
            static fn (Component $component): string => $component->id->value,
            $this->componentRegistry()->enclosing($focused),
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
        return $this->stoppingWhatLeft($this->routed($message, $screenRect, $focus));
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
     * @return list<ComponentId>
     */
    final public function enclosingIds(ComponentId $id): array
    {
        return array_map(
            static fn (Component $component): ComponentId => $component->id,
            $this->componentRegistry()->enclosing($id),
        );
    }

    /**
     * A global chord binds only while the focused component or a container around
     * it answers its action; otherwise its first key is just a key.
     */
    final public function chords(FocusState $focus): Keybindings
    {
        $answering = Keybindings::empty();
        $focused = $this->componentRegistry()->focusable($focus->focused());

        foreach ($focused === null ? [] : [$focused, ...$this->componentRegistry()->enclosing($focused->id)] as $component) {
            if ($component->widget instanceof KeybindingsAware) {
                $answering = $answering->append($component->widget->effectiveKeybindings());
            }
        }

        $chords = $this->applicationKeybindings()
            ->append($answering)
            ->append($this->globalKeybindings()->restrictedTo(...$answering->actions()))
            ->chords();

        $holder = $focus->focused();
        foreach ($this->mountsHearingKeys($holder) as $mount) {
            if ($mount->model instanceof BindsChords) {
                $inside = $holder !== null && $holder->isUnder($mount->id)
                    ? ComponentId::named(substr($holder->value, \strlen($mount->id->value) + 1))
                    : null;
                $chords = $chords->append($mount->model->chords(new FocusState($inside, decided: true))->chords());
            }
        }

        return $this->takesText($focus->focused()) ? $chords->withoutText() : $chords;
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

    /**
     * How to label the keys that open the key help, for a footer to show: all of
     * them, since a field that takes `?` as text leaves only the other.
     *
     * @return array<string, string>
     */
    public function keyHelpHints(string $description = 'keys'): array
    {
        $labels = array_map(static fn (KeyPattern $pattern): string => $pattern->label(), $this->keyHelpBindings()->get(Actions::HELP));

        return $labels === [] ? [] : [implode('/', $labels) => $description];
    }

    final public function opensKeyHelp(KeyMsg $key, FocusState $focus): bool
    {
        if ($key->isText() && $this->takesText($focus->focused())) {
            return false;
        }

        return $this->keyHelpBindings()->matches($key, Actions::HELP);
    }

    final public function keyHelp(FocusState $focus): array
    {
        $globals = $this->resolvedGlobalKeybindings();
        $focused = $this->componentRegistry()->focusable($focus->focused());
        $groups = [];

        if ($focused !== null) {
            $groups['In the footer'] = $this->hintsOfFocused($focused->id);
            $groups['Here'] = $this->keysOf($focused->widget, $globals);
            $groups['Around it'] = [];
            foreach (array_reverse($this->componentRegistry()->enclosing($focused->id)) as $component) {
                $groups['Around it'] += $this->keysOf($component->widget, $globals);
            }
        }

        foreach ($this->mountsHearingKeys($focus->focused()) as $mount) {
            if ($mount->model instanceof NamesItsKeys) {
                $groups[ucfirst($mount->id->value)] = $mount->model->ownKeys();
            }
        }

        $groups['Anywhere'] = $this->described(
            $this->applicationKeybindings()
                ->append($this->resolvedQuitBindings())
                ->append($this->focusCycleBindings())
                ->append($this->keyHelpBindings()),
        );
        $groups['In every pane'] = $this->described($globals);

        return $groups;
    }

    /**
     * The theme to draw the next frame in, from what this App holds; null keeps
     * the one the program started with.
     */
    public function theme(): ?Theme
    {
        return null;
    }

    protected function setup(): ?Cmd
    {
        return null;
    }

    /**
     * App-level key hook, consulted before the focused component. A key that is
     * text goes straight to a focused component that takes text (TakesText).
     *
     * Returning a changed model or any command consumes the key — it will
     * NOT reach the focused component. Return UpdateResult::ignored($this) to
     * pass the key on. Keys matched by applicationKeybindings() never
     * arrive here; they go to onAction().
     *
     * @return UpdateResult<static>
     */
    protected function onKey(KeyMsg $message, Rect $screen): UpdateResult
    {
        return UpdateResult::ignored($this);
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
            'quit' => $this->then(Cmd::quit()),
            default => UpdateResult::ignored($this),
        };
    }

    /**
     * Reserved application shortcuts, evaluated before focused components,
     * except a key that is text while the focused component takes text.
     * Widget action inheritance belongs in globalKeybindings() instead.
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
        return UpdateResult::ignored($this);
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
        return UpdateResult::ignored($this);
    }

    /**
     * @return UpdateResult<static>
     */
    protected function onMouse(MouseMsg $message, Rect $screen, Keyboard $keyboard): UpdateResult
    {
        return UpdateResult::ignored($this);
    }

    /**
     * @return UpdateResult<static>
     */
    protected function onTick(TickMsg $message, Rect $screen): UpdateResult
    {
        return UpdateResult::ignored($this);
    }

    /**
     * @return UpdateResult<static>
     */
    protected function onMessage(Message $message, Rect $screen): UpdateResult
    {
        return UpdateResult::ignored($this);
    }

    /**
     * The mounts this App holds but does not draw now, such as hidden tabs, so
     * they start and hear their answers too. The mounts in the view are added.
     *
     * @return list<Mount<*>>
     */
    protected function mounts(): array
    {
        return [];
    }

    protected function geometry(Rect $screen): Geometry
    {
        return Geometry::from(LayoutSnapshot::planFor($this, $screen, $this->viewWidget(...)), $this->componentRegistry());
    }

    /**
     * Extra keys that leave, on top of the ctrl chords applicationKeybindings()
     * already reserves. A letter here is typed instead while the focused
     * component takes text.
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
    protected function then(Cmd $command): UpdateResult
    {
        return new UpdateResult($this, $command);
    }

    /**
     * Which keys move the focus from one bound component to the next. Override
     * to put focus cycling somewhere else; a form that types tab characters is
     * the usual reason.
     */
    /**
     * The keys that open the key help: `?`, and F1 for while a field takes `?` as
     * text. Return Keybindings::empty() to offer none.
     */
    protected function keyHelpBindings(): Keybindings
    {
        return new Keybindings([Actions::HELP => ['?', 'f1']]);
    }

    /**
     * What an action does, in the words of the key help. Override to name the
     * actions this app binds itself.
     */
    protected function actionDescription(string $action): string
    {
        return Actions::description($action);
    }

    protected function focusCycleBindings(): Keybindings
    {
        return new Keybindings([
            Actions::FOCUS_CYCLE => ['tab'],
            Actions::FOCUS_PREVIOUS_CYCLE => ['shift+tab'],
        ]);
    }

    /**
     * @param UpdateResult<static> $result
     *
     * @return UpdateResult<static>
     */
    private function stoppingWhatLeft(UpdateResult $result): UpdateResult
    {
        $app = $result->model;
        if ($app === $this) {
            return $result;
        }
        $mounts = $this->allMounts();
        if ($mounts === []) {
            return $result;
        }

        $kept = array_map(static fn (Mount $mount): string => $mount->id->value, $app->allMounts());
        $left = array_values(array_filter($mounts, static fn (Mount $mount): bool => !\in_array($mount->id->value, $kept, true)));

        return $left === []
            ? $result
            : $result->withCommand(Cmd::combine(...[...array_map(static fn (Mount $mount): Cmd => $mount->left(), $left), $result->command]));
    }

    /**
     * @return UpdateResult<static>
     */
    private function routed(Message $message, Rect $screenRect, FocusState $focus): UpdateResult
    {
        if ($message instanceof KeyMsg && $message->isText() && $this->takesText($focus->focused())) {
            return $this->delegateToFocused($message, $screenRect, $focus->focused());
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

        if ($registry->focusables() === []) {
            return $this->routeWithoutFocus($message, $screenRect, $registry->all());
        }

        return $this->routeByFocus($message, $screenRect, $focus->focused());
    }

    /**
     * @param list<Component<Interactive>> $components
     *
     * @return UpdateResult<static>
     */
    private function routeWithoutFocus(Message $message, Rect $screenRect, array $components): UpdateResult
    {
        if ($message instanceof KeyMsg) {
            if ($this->resolvedQuitBindings()->matches($message, 'quit')) {
                return new UpdateResult($this, Cmd::quit());
            }

            $appResult = $this->onKey($message, $screenRect);

            return $appResult->model !== $this || $appResult->command !== null
                ? $appResult
                : $this->offeredToMounts($message, $screenRect, null) ?? $appResult;
        }

        if ($message instanceof MouseMsg) {
            return $this->delegateMouse($message, $screenRect, $components);
        }

        return $this->handleUnrouted($message, $screenRect);
    }

    /**
     * @return UpdateResult<static>
     */
    private function routeByFocus(Message $message, Rect $screenRect, ?ComponentId $holder): UpdateResult
    {
        if ($message instanceof KeyMsg) {
            $bindings = $this->focusCycleBindings();

            if ($bindings->matches($message, Actions::FOCUS_PREVIOUS_CYCLE)) {
                return $this->handleTab($message, $screenRect, $holder, true);
            }

            if ($bindings->matches($message, Actions::FOCUS_CYCLE)) {
                return $this->handleTab($message, $screenRect, $holder, $message->shift);
            }

            return $this->routeKeyByFocus($message, $screenRect, $holder);
        }

        if ($message instanceof MouseMsg) {
            return $this->delegateMouse($message, $screenRect, $this->componentRegistry()->all(), $holder);
        }

        if ($message instanceof FocusMsg) {
            return $this->delegateArrival($message, $screenRect, $holder);
        }

        // Handed to every component, a paste would fill every field of a form at once.
        if ($message instanceof PasteMsg) {
            return $this->delegateToFocused($message, $screenRect, $holder);
        }

        return $this->handleUnrouted($message, $screenRect);
    }

    /**
     * The containers above hear where below them the keyboard landed; the
     * component itself hears the message.
     *
     * @return UpdateResult<static>
     */
    private function delegateArrival(FocusMsg $message, Rect $screenRect, ?ComponentId $holder): UpdateResult
    {
        $focused = $this->componentRegistry()->focusable($holder);

        if ($focused === null) {
            return new UpdateResult($this);
        }

        $updates = $this->keyboardAnnouncements($focused);
        $command = null;
        $rect = $this->findComponentRects($screenRect, [$focused])[$focused->id->value] ?? null;

        if ($focused->writesBack() && $rect !== null) {
            /** @var Interactive $widget */
            $widget = $focused->widget;
            $dispatch = $this->childDispatcher()->dispatchOne($widget, $message, $rect);

            if ($dispatch->changed) {
                $updates[] = [$focused, $dispatch->child];
            }

            $command = $focused->addressed($dispatch->command);
        }

        [$current, $hookCommand] = $this->applyComponentUpdatesAndNotify($this, $updates, $message);

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

        foreach ($this->componentRegistry()->enclosing($focused->id) as $ancestor) {
            /** @var Interactive $container */
            $container = $ancestor->widget;

            if (!$ancestor->writesBack() || !$container instanceof FollowsTheKeyboard || !$container instanceof WidgetContainer) {
                continue;
            }

            $child = $focused->id->isUnder($ancestor->id)
                ? $focused->id->pathBelow($ancestor->id)[0]
                : ComponentRegistry::childHolding($container, $focused->id);

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

    /**
     * What neither layer routes by hand. A tick and a click on a float reach every
     * component once the app's hook has had it; anything else is the app's alone.
     * Both layers answer these the same way, so they answer them in one place.
     *
     * @return UpdateResult<static>
     */
    private function handleUnrouted(Message $message, Rect $screenRect): UpdateResult
    {
        if ($message instanceof MountedMsg) {
            $mount = $this->mountNamed($message->mount);

            return $mount === null
                ? UpdateResult::ignored($this)
                : $this->answeredBy($mount, $message->message, $screenRect);
        }

        if ($message instanceof TickMsg) {
            return $this->broadcastAfter($this->onTick($message, $screenRect), $message, $screenRect);
        }

        if ($message instanceof FloatMouseMsg) {
            return $this->broadcastAfter($this->onMessage($message, $screenRect), $message, $screenRect);
        }

        return $this->onMessage($message, $screenRect);
    }

    /**
     * @return Mount<*>|null
     */
    private function mountNamed(ComponentId $id): ?Mount
    {
        foreach ($this->allMounts() as $mount) {
            if ($mount->id->equals($id)) {
                return $mount;
            }
        }

        return null;
    }

    /**
     * @return list<Mount<*>>
     */
    private function allMounts(): array
    {
        $mounts = [];
        foreach ([...$this->componentRegistry()->mounts(), ...$this->mounts()] as $mount) {
            $mounts[$mount->id->value] ??= $mount;
        }

        return array_values($mounts);
    }

    /**
     * Updates the mounted model and writes it back into the App. What the model
     * asks for is marked so the answer comes back to it.
     *
     * @param Mount<*> $mount
     *
     * @return UpdateResult<static>
     */
    private function answeredBy(Mount $mount, Message $message, Rect $screenRect): UpdateResult
    {
        $result = $mount->model->update($message, $this->areaOf($mount, $screenRect));
        $command = $mount->addressed($result->command);

        if ($result->model === $mount->model) {
            return new UpdateResult($this, $command);
        }

        $app = $mount->writtenBack($this, $result->model);
        if (!$app instanceof static) {
            throw new LogicException(sprintf('Mount "%s" must write back a %s, got %s', $mount->id->value, static::class, $app::class));
        }

        return new UpdateResult($app, $command);
    }

    /**
     * The area the mount is drawn in, or the whole screen if it is not drawn.
     *
     * @param Mount<*> $mount
     */
    private function areaOf(Mount $mount, Rect $screenRect): Rect
    {
        foreach ($this->componentRegistry()->mounts() as $drawn) {
            $rect = $drawn->id->equals($mount->id) ? LayoutPlan::rectOf($this->viewWidget(), $screenRect, $drawn) : null;

            if ($rect !== null) {
                return $rect;
            }
        }

        return $screenRect;
    }

    /**
     * @return UpdateResult<static>
     */
    private function handleTab(KeyMsg $message, Rect $screenRect, ?ComponentId $holder, bool $reverse): UpdateResult
    {
        $focused = $this->componentRegistry()->focusable($holder);

        if ($focused !== null && $focused->writesBack()) {
            /** @var Focusable $widget */
            $widget = $focused->widget;
            $rect = $this->findComponentRects($screenRect, [$focused])[$focused->id->value] ?? null;

            if ($rect === null) {
                return new UpdateResult($this, $reverse ? Cmd::focusPrevious() : Cmd::focusNext());
            }

            $dispatch = $this->childDispatcher()->dispatchOne($widget, $message, $rect);

            if ($dispatch->changed || $dispatch->command !== null) {
                [$current, $hookCommand] = $dispatch->changed
                    ? $this->applyComponentUpdatesAndNotify($this, $this->focusedUpdate($focused, $dispatch->child), $message)
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
     * @return UpdateResult<static>
     */
    private function routeKeyByFocus(KeyMsg $message, Rect $screenRect, ?ComponentId $holder): UpdateResult
    {
        if ($this->resolvedQuitBindings()->matches($message, 'quit')) {
            return new UpdateResult($this, Cmd::quit());
        }

        $appResult = $this->onKey($message, $screenRect);

        if ($appResult->model !== $this || $appResult->command !== null) {
            return $appResult;
        }

        return $this->offeredToMounts($message, $screenRect, $holder)
            ?? $this->delegateToFocused($message, $screenRect, $holder);
    }

    /**
     * A mounted model gets a key before its components do. The key goes to the
     * mount that holds the keyboard; if no mount holds it, to every mount on screen.
     *
     * @return UpdateResult<static>|null
     */
    private function offeredToMounts(KeyMsg $message, Rect $screenRect, ?ComponentId $holder): ?UpdateResult
    {
        foreach ($this->mountsHearingKeys($holder) as $mount) {
            $answered = $this->answeredBy($mount, $message, $screenRect);

            if ($answered->model !== $this || $answered->command !== null) {
                return $answered;
            }
        }

        return null;
    }

    /**
     * The innermost mount holding the keyboard, else every mount on screen.
     *
     * @return list<Mount<*>>
     */
    private function mountsHearingKeys(?ComponentId $holder): array
    {
        $drawn = $this->componentRegistry()->listeningMounts();
        $holding = array_values(array_filter(
            $drawn,
            static fn (Mount $mount): bool => $holder !== null && $holder->isUnder($mount->id),
        ));

        return $holding === [] ? $drawn : [end($holding)];
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
     * @return UpdateResult<static>
     */
    private function delegateToFocused(Message $message, Rect $screenRect, ?ComponentId $holder): UpdateResult
    {
        $focused = $this->componentRegistry()->focusable($holder);

        if ($focused === null || !$focused->writesBack()) {
            return new UpdateResult($this);
        }

        /** @var Focusable $widget */
        $widget = $focused->widget;
        $rect = $this->findComponentRects($screenRect, [$focused])[$focused->id->value] ?? null;

        if ($rect === null) {
            return $this->offeredToWhatEncloses($message, $screenRect, $focused);
        }

        $dispatch = $this->childDispatcher()->dispatchOne($widget, $message, $rect);

        if ($dispatch->changed || ($widget instanceof Committing && $widget->commits($message))) {
            [$current, $hookCommand] = $this->applyComponentUpdatesAndNotify($this, $this->focusedUpdate($focused, $dispatch->child), $message);

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
        if (($focused->widget instanceof CapturesKeys && $focused->widget->capturesKeys())
            || ($message instanceof KeyMsg && $message->isText() && $this->takesText($focused->id))
        ) {
            return new UpdateResult($this);
        }

        $enclosing = array_values(array_filter(
            $this->componentRegistry()->enclosing($focused->id),
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
                [$current, $hookCommand] = $this->applyComponentUpdatesAndNotify($this, [[$candidate, $dispatch->child]], $message);

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
        $appResult = $this->onMouse($message, $screenRect, new Keyboard($holder, fn (): array => $this->hintsOfFocused($holder)));
        $pressed = $this->appModel($appResult)->offeredToTheMountUnder($message, $screenRect);
        $appResult = new UpdateResult($pressed->model, self::combineCommands($appResult->command, $pressed->command));
        $current = $this->appModel($appResult);

        // A press goes only to what was on screen when it was made. A popup the press
        // opened would otherwise read it as a click outside its box and close.
        if ($current !== $this) {
            $before = array_flip(array_map(static fn (Component $component): string => $component->id->value, $components));
            $components = array_values(array_filter(
                $current->componentRegistry()->all(),
                static fn (Component $component): bool => isset($before[$component->id->value]),
            ));
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

        // Never write a flag back to move the keyboard: that runs the closure of a
        // component the pointer never touched, over what the touched one just changed.
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

        if ($landedOn === null && $message->isClick()) {
            $landedOn = $current->containerEnteredBy($message, $visible, $holder);
        }

        [$next, $hookCommand] = $this->applyComponentUpdatesAndNotify($current, $updates, $message);
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
     * @return UpdateResult<static>
     */
    private function offeredToTheMountUnder(MouseMsg $message, Rect $screenRect): UpdateResult
    {
        $rects = LayoutSnapshot::planFor($this, $screenRect, $this->viewWidget(...))->visibleRects();
        $under = null;

        foreach ($this->componentRegistry()->listeningMounts() as $mount) {
            if ($rects->contains($mount) && $message->inRect($rects[$mount])) {
                $under = $mount;
            }
        }

        return $under === null ? new UpdateResult($this) : $this->answeredBy($under, $message, $screenRect);
    }

    /**
     * A press on a plain row of a container whose children are focus stops moves
     * the keyboard into the container, if it was outside. If it was already
     * inside, it stays on its field.
     *
     * @param array<string, Rect> $visible
     */
    private function containerEnteredBy(MouseMsg $message, array $visible, ?ComponentId $holder): ?ComponentId
    {
        $stops = $this->componentRegistry()->focusables();
        $entered = null;

        foreach ($this->componentRegistry()->all() as $component) {
            $rect = $visible[$component->id->value] ?? null;
            $holdsStops = array_filter($stops, static fn (Component $stop): bool => $stop->id->isUnder($component->id)) !== [];

            if ($rect !== null && $message->inRect($rect) && $holdsStops && ($entered === null || $component->id->isUnder($entered))) {
                $entered = $component->id;
            }
        }

        return $entered === null || ($holder !== null && ($holder->equals($entered) || $holder->isUnder($entered)))
            ? null
            : $entered;
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

        [$next, $hookCommand] = $this->applyComponentUpdatesAndNotify($current, $updates, $message);

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
        // the way in and unwraps again, so nothing kept has them. The focus cycle
        // keys are added here only, so a widget can name them but never answers them.
        if ($widget instanceof KeybindingsAware) {
            $widget = $widget->withGlobalKeybindings($this->globalKeybindings()->append($this->focusCycleBindings()));
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
        return $dispatchers[$this] ??= new ChildDispatcher($this->resolvedGlobalKeybindings());
    }

    /**
     * The keys a widget answers, with the global ones it answers too.
     *
     * @return array<string, string>
     */
    private function keysOf(Widget $widget, Keybindings $globals): array
    {
        if (!$widget instanceof KeybindingsAware) {
            return [];
        }

        $own = $widget->withGlobalKeybindings(null)->effectiveKeybindings()->actions();

        return $this->described($widget->withGlobalKeybindings($globals)->effectiveKeybindings()->restrictedTo(...$own));
    }

    /**
     * @return array<string, string> key label => what it does
     */
    private function described(Keybindings $bindings): array
    {
        $described = [];

        foreach ($bindings->actions() as $action) {
            $labels = array_values(array_unique(array_map(static fn (KeyPattern $pattern): string => $pattern->label(), $bindings->get($action))));

            if ($labels === []) {
                continue;
            }

            $label = implode('/', $labels);
            $description = $this->actionDescription($action);
            $described[$label] = isset($described[$label]) ? $described[$label] . ', ' . $description : $description;
        }

        return $described;
    }

    private function resolvedGlobalKeybindings(): Keybindings
    {
        static $resolved = null;
        $resolved ??= new WeakMap();

        /**
         * @var WeakMap<object, Keybindings> $resolved
         */
        return $resolved[$this] ??= $this->globalKeybindings();
    }

    private function takesText(?ComponentId $holder): bool
    {
        $widget = $this->componentRegistry()->focusable($holder)?->widget;

        if ($widget instanceof KeybindingsAware) {
            $widget = $widget->withGlobalKeybindings($this->resolvedGlobalKeybindings());
        }

        return $widget instanceof TakesText && $widget->takesText();
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
        return ($bindings[$this] ??= $this->applicationKeybindings())->actionOf($message);
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
    private function applyComponentUpdatesAndNotify(self $app, array $updates, Message $message): array
    {
        $updates = ComponentWriteBack::withoutLetGo($updates);
        if ($updates === []) {
            return [$app, null];
        }

        [$updated, $written] = $this->applyComponentUpdates($app, $updates, $message);

        $ids = [];
        foreach ($updates as [$original]) {
            $ids[] = $original->id;
        }

        $result = $updated->onComponentsChanged($ids);

        return [$updated->appModel($result), self::combineCommands($written, $result->command)];
    }

    /**
     * @param static                                                       $app
     * @param array<array-key, array{Component<Interactive>, Interactive}> $updates
     *
     * @return array{static, ?Cmd} the App, and what writing the components back asked for
     */
    private function applyComponentUpdates(self $app, array $updates, Message $message): array
    {
        $commands = [];

        foreach (ComponentWriteBack::foldedIntoAncestors($updates) as [$original, $widget]) {
            // A mounted component writes back through the sub-model the tree held
            // when it was collected, so it is looked up in the app as it stands or a
            // second write restores the sub-model the first one changed.
            $current = $app->componentRegistry()->get($original->id) ?? $original;
            $replaced = $current->replaceIn($app, $widget, $message);
            ComponentWriteBack::verifyStored($current, $widget, $app, $replaced->model);
            /** @var static $app */
            $app = $replaced->model;
            $commands[] = $replaced->command;
        }

        ComponentWriteBack::verify($app->componentRegistry(), $updates);

        return [$app, self::combineCommands(...$commands)];
    }
}
