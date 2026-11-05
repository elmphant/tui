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
use Elmphant\Tui\Input\BindsForTheMoment;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Input\KeybindingsAware;
use Elmphant\Tui\Input\TakesText;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\ActionMsg;
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
use Elmphant\Tui\Runtime\SizesItsRegion;
use Elmphant\Tui\Support\Cloneable;
use Elmphant\Tui\Widget\Committing;
use Elmphant\Tui\Widget\Dispatch\ChildDispatcher;
use Elmphant\Tui\Widget\Focus\CapturesKeys;
use Elmphant\Tui\Widget\Focus\FollowsTheKeyboard;
use Elmphant\Tui\Widget\Focus\InputLayer;
use Elmphant\Tui\Widget\Focusable;
use Elmphant\Tui\Widget\Interactive;
use Elmphant\Tui\Widget\MeasureHeight;
use Elmphant\Tui\Widget\Plan\LayoutPlan;
use Elmphant\Tui\Widget\Plan\LayoutSnapshot;
use Elmphant\Tui\Widget\PublishesHints;
use Elmphant\Tui\Widget\Widget;
use Elmphant\Tui\Widget\WidgetContainer;
use LogicException;
use WeakMap;

abstract readonly class App implements Initializes, RoutesFocus, ChoosesItsTheme, BindsChords, DescribesKeys, SizesItsRegion
{
    use BindsProperties;
    use Cloneable;

    /**
     * Keep this a pure function of the immutable App, including objects it holds: the tree and layout are cached per instance.
     * For animation, read RenderContext::now() during render.
     */
    abstract public function view(): Widget;

    final public function wantedHeight(int $width): int
    {
        $root = $this->viewWidget();

        return $root instanceof MeasureHeight ? $root->measureHeight($width) : PHP_INT_MAX;
    }

    final public function init(): ?Cmd
    {
        return Cmd::batch($this->setup(), ...array_map(
            static fn (Mount $mount): ?Cmd => $mount->model instanceof Initializes ? $mount->addressed($mount->model->init()) : null,
            $this->allMounts(),
        ));
    }

    final public function render(Frame $frame, RenderPass $pass): void
    {
        $area = $pass->areaIn($frame);
        $focused = $pass->focus->focusedComponent();

        $pass->focus->provideHints($this->hintsOfFocused($focused));
        $pass->focus->provideKeyHelpHint($this->keyHelpHint($focused));
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

    final public function update(Message $message, Rect $area, ?FocusState $focus = null): UpdateResult
    {
        return $this->stoppingWhatLeft($this->routed($message, $area, $focus ?? FocusState::undecided()));
    }

    /**
     * @return list<ComponentId>
     */
    final public function ringIds(?FocusState $focus = null): array
    {
        return array_map(
            static fn (Component $component): ComponentId => $component->id,
            $this->componentRegistry()->ring($focus?->focused()),
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
     * Global chords apply only when the focused component or an ancestor handles their action.
     */
    final public function chords(FocusState $focus): Keybindings
    {
        $answering = Keybindings::empty();
        $focused = $this->componentRegistry()->focusable($focus->focused());

        foreach ($focused === null ? [] : [$focused, ...$this->componentRegistry()->enclosing($focused->id)] as $component) {
            if ($component->widget instanceof KeybindingsAware) {
                $answering = $answering->plus($component->widget->effectiveKeybindings());
            }
        }

        $chords = $this->declaredActions()->keybindings()
            ->plus($answering)
            ->plus($this->globalKeybindings()->restrictedTo(...$answering->actions()))
            ->chords();

        $holder = $focus->focused();
        foreach ($this->mountsHearingKeys($holder) as $mount) {
            if ($mount->model instanceof BindsChords) {
                $inside = $holder?->below($mount->id);
                $chords = $chords->plus($mount->model->chords($inside === null ? FocusState::nowhere() : FocusState::on($inside))->chords());
            }
        }

        return $this->takesText($focus->focused()) ? $chords->withoutText() : $chords;
    }

    final public function opensKeyHelp(KeyMsg $key, FocusState $focus): bool
    {
        if ($key->isText() && $this->takesText($focus->focused())) {
            return false;
        }

        return $this->keyHelpKeybindings()->matches($key, Actions::HELP);
    }

    final public function keyHelp(FocusState $focus): array
    {
        // List each key once, under the closest group that handles it.
        // List global keys only when a visible component handles their action.
        $globals = $this->resolvedGlobalKeybindings();
        $focused = $this->componentRegistry()->focusable($focus->focused());
        $listed = [];
        $groups = [];

        if ($focused !== null) {
            $wording = $this->hintsOfFocused($focused->id);
            $groups['Here'] = [];
            foreach ($this->componentsWithActionsAround($focused->id) as $component) {
                $groups['Here'] += $this->described($component->actions(), $listed, $wording);
            }
            $groups['Here'] += $this->keysOf($focused->widget, $globals, $listed, $wording);
            $groups['Around it'] = [];
            foreach (array_reverse($this->componentRegistry()->enclosing($focused->id)) as $component) {
                $widget = $component->widget;
                $wording = $widget instanceof PublishesHints && $widget instanceof KeybindingsAware
                    ? $widget->withGlobalKeybindings($globals)->hints()
                    : [];
                $groups['Around it'] += $this->keysOf($widget, $globals, $listed, $wording);
            }
        }

        foreach ($this->mountsHearingKeys($focus->focused()) as $mount) {
            if ($mount->model instanceof NamesItsKeys) {
                $own = $mount->model->ownKeys();
                foreach (array_keys($own) as $label) {
                    $label = (string)$label;
                    foreach ($label === '/' ? ['/'] : explode('/', $label) as $key) {
                        $listed[$key] = true;
                    }
                }
                $groups[ucfirst($mount->id->value)] = $own;
            }
        }

        $groups['Anywhere'] = $this->described(
            $this->declaredActions()->keybindings()
                ->plus($this->focusCycleKeybindings())
                ->plus($this->keyHelpKeybindings()),
            $listed,
            $this->appActionHints($this->declaredActions()->keybindings()),
        );

        return $groups;
    }

    /**
     * Null keeps the program's initial theme.
     */
    public function theme(): ?Theme
    {
        return null;
    }

    /**
     * Starts commands only. The starting state comes from the constructor or a factory.
     */
    protected function setup(): ?Cmd
    {
        return null;
    }

    /**
     * Runs before the focused component, except for keys it takes as text; a key actions() declares never reaches it.
     * A changed model or any command consumes the key; return $this to pass it on.
     *
     * @return static|UpdateResult<static>
     */
    protected function onKey(KeyMsg $message, InputContext $input): self|UpdateResult
    {
        return $this;
    }

    /**
     * Keys answered wherever the keyboard is, before the focused component, except a key it takes as text.
     * The footer leads with them and the key help lists them. Declare `Actions::QUIT` again to quit with `q` too, or to ask first.
     */
    protected function actions(): AppActions
    {
        return AppActions::new();
    }

    /**
     * @return static|UpdateResult<static>
     */
    protected function onResize(ResizeMsg $message): self|UpdateResult
    {
        return $this;
    }

    /**
     * Runs after updated widgets are stored, so the app can update dependent state.
     * Returned commands run alongside the child's commands.
     *
     * @param list<ComponentId> $ids
     *
     * @return static|UpdateResult<static>
     */
    protected function onComponentsChanged(array $ids): self|UpdateResult
    {
        return $this;
    }

    /**
     * @return static|UpdateResult<static>
     */
    protected function onMouse(MouseMsg $message, InputContext $input): self|UpdateResult
    {
        return $this;
    }

    /**
     * @return static|UpdateResult<static>
     */
    protected function onTick(TickMsg $message, InputContext $input): self|UpdateResult
    {
        return $this;
    }

    /**
     * @return static|UpdateResult<static>
     */
    protected function onMessage(Message $message, InputContext $input): self|UpdateResult
    {
        return $this;
    }

    /**
     * Include hidden mounts so they can start and receive results; visible mounts are added automatically.
     *
     * @return list<Mount<*>>
     */
    protected function mounts(): array
    {
        return [];
    }

    final protected function geometry(Rect $screen): Geometry
    {
        return Geometry::from(LayoutSnapshot::planFor($this, $screen, $this->viewWidget(...)), $this->componentRegistry());
    }

    protected function globalKeybindings(): Keybindings
    {
        return Keybindings::empty();
    }

    /**
     * Ctrl+K opens help when a field takes `?` as text; return Keybindings::empty() to disable help.
     */
    protected function keyHelpKeybindings(): Keybindings
    {
        return new Keybindings([Actions::HELP => ['?', 'ctrl+k']]);
    }

    /**
     * Override the focus-cycle keys, for example when a form needs Tab as text.
     */
    protected function focusCycleKeybindings(): Keybindings
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
            : $result->withCommand(Cmd::batch(...[...array_map(static fn (Mount $mount): Cmd => $mount->left(), $left), $result->command]));
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
            $answered = $this->answeredByComponentAction($message, $focus->focused());
            if ($answered !== null) {
                return $answered;
            }

            $answered = $this->answeredByAppAction($message);
            if ($answered !== null) {
                return $answered;
            }
        }

        if ($message instanceof ResizeMsg) {
            return $this->resultOf($this->onResize($message));
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
            return $this->routeKey($message, $screenRect, null);
        }

        if ($message instanceof MouseMsg) {
            return $this->delegateMouse($message, $screenRect, $components);
        }

        return $this->handleUnrouted($message, $screenRect, null);
    }

    /**
     * @return UpdateResult<static>
     */
    private function routeByFocus(Message $message, Rect $screenRect, ?ComponentId $holder): UpdateResult
    {
        if ($message instanceof KeyMsg) {
            $bindings = $this->focusCycleKeybindings();

            if ($bindings->matches($message, Actions::FOCUS_PREVIOUS_CYCLE)) {
                return $this->handleTab($message, $screenRect, $holder, true);
            }

            if ($bindings->matches($message, Actions::FOCUS_CYCLE)) {
                return $this->handleTab($message, $screenRect, $holder, $message->shift);
            }

            return $this->routeKey($message, $screenRect, $holder);
        }

        if ($message instanceof MouseMsg) {
            return $this->delegateMouse($message, $screenRect, $this->componentRegistry()->all(), $holder);
        }

        if ($message instanceof FocusMsg) {
            return $this->delegateArrival($message, $screenRect, $holder);
        }

        // Send paste only to the focused component, or every field would receive it.
        if ($message instanceof PasteMsg) {
            return $this->delegateToFocused($message, $screenRect, $holder);
        }

        return $this->handleUnrouted($message, $screenRect, $holder);
    }

    /**
     * Notify ancestor containers of the focused child, then deliver the message to that child.
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

            $command = $dispatch->command;
        }

        return $this->writtenBack($focused, $updates, $command, $message);
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
     * After the app hook, broadcast ticks and float clicks; other messages belong to the app alone.
     *
     * @return UpdateResult<static>
     */
    private function handleUnrouted(Message $message, Rect $screenRect, ?ComponentId $holder): UpdateResult
    {
        if ($message instanceof MountedMsg) {
            $mount = $this->mountNamed($message->mount);

            return $mount === null
                ? new UpdateResult($this)
                : $this->answeredBy($mount, $message->message, $screenRect);
        }

        if ($message instanceof TickMsg) {
            return $this->broadcastAfter($this->resultOf($this->onTick($message, $this->inputContext($screenRect, $holder))), $message, $screenRect);
        }

        if ($message instanceof FloatMouseMsg) {
            return $this->broadcastAfter($this->resultOf($this->onMessage($message, $this->inputContext($screenRect, $holder))), $message, $screenRect);
        }

        return $this->resultOf($this->onMessage($message, $this->inputContext($screenRect, $holder)));
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
     * Store the updated mounted model and route its effect results back to it.
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
     * Use the mount's drawn area, or the whole screen for a hidden mount.
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
                return $this->writtenBack(
                    $focused,
                    $dispatch->changed ? $this->focusedUpdate($focused, $dispatch->child) : [],
                    $dispatch->command,
                    $message,
                );
            }
        }

        return new UpdateResult($this, $reverse ? Cmd::focusPrevious() : Cmd::focusNext());
    }

    /**
     * @return UpdateResult<static>
     */
    private function routeKey(KeyMsg $message, Rect $screenRect, ?ComponentId $holder): UpdateResult
    {
        $appResult = $this->resultOf($this->onKey($message, $this->inputContext($screenRect, $holder)));

        if ($appResult->model !== $this || $appResult->command !== null) {
            return $appResult;
        }

        return $this->offeredToMounts($message, $screenRect, $holder)
            ?? $this->delegateToFocused($message, $screenRect, $holder);
    }

    /**
     * Offer keys to the focused mount before its components, or to all visible mounts if none has focus.
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
        $geometry = $this->geometry($screenRect);
        $result = [];

        foreach ($components as $component) {
            $rect = $visible ? $geometry->visibleRectOf($component->id) : $geometry->rectOf($component->id);

            if ($rect !== null) {
                $result[$component->id->value] = $rect;
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
        $outcome = $widget instanceof Committing ? $widget->outcomeOf($message, $rect) : null;

        if ($dispatch->changed || $outcome !== null) {
            return $this->writtenBack($focused, $this->focusedUpdate($focused, $dispatch->child), $dispatch->command, $outcome ?? $message);
        }

        if ($dispatch->command !== null) {
            return new UpdateResult($this, $focused->addressed($dispatch->command));
        }

        return $this->offeredToWhatEncloses($message, $screenRect, $focused);
    }

    /**
     * Bring the focused component back into view after it handles input.
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
     * Pass unhandled keys to ancestor containers so a pane can scroll while a field has focus.
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

        // Reverse pre-order so the innermost container gets the key first.
        foreach (array_reverse($enclosing) as $candidate) {
            $rect = $rects[$candidate->id->value] ?? $screenRect;
            $outcome = $candidate->widget instanceof Committing ? $candidate->widget->outcomeOf($message, $rect) : null;
            $dispatch = $this->childDispatcher()->dispatchOne($candidate->widget, $message, $rect);

            if ($dispatch->changed || $outcome !== null) {
                return $this->writtenBack($candidate, [[$candidate, $dispatch->child]], $dispatch->command, $outcome ?? $message);
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
        $appResult = $this->resultOf($this->onMouse($message, $this->inputContext($screenRect, $holder)));
        $pressed = $this->appModel($appResult)->offeredToTheMountUnder($message, $screenRect);
        $appResult = new UpdateResult($pressed->model, Cmd::batch($appResult->command, $pressed->command));
        $current = $this->appModel($appResult);

        // Only widgets visible before this press may handle it; a newly opened popup would otherwise close immediately.
        if ($current !== $this) {
            $before = array_flip(array_map(static fn (Component $component): string => $component->id->value, $components));
            $components = array_values(array_filter(
                $current->componentRegistry()->all(),
                static fn (Component $component): bool => isset($before[$component->id->value]),
            ));
        }
        $rects = $current->findComponentRects($screenRect, $components);
        $visible = $current->findComponentRects($screenRect, $components, visible: true);

        // Scrolled-out children still have rectangles but must not receive clicks.
        $components = array_values(array_filter(
            $components,
            static fn (Component $component): bool => !isset($rects[$component->id->value])
                || !$message->isIn($rects[$component->id->value])
                || (isset($visible[$component->id->value]) && $message->isIn($visible[$component->id->value])),
        ));
        [$updates, $childCommand, $outcome] = $current->dispatchComponents(
            $components,
            $rects,
            $message,
            $current->childDispatcher(),
            $holder,
        );

        // Moving focus must not write back an untouched component and overwrite the clicked component's changes.
        $landedOn = null;
        if ($message->isClick() || $message->isWheel()) {
            foreach ($current->componentRegistry()->focusables() as $component) {
                $rect = $visible[$component->id->value] ?? null;

                if ($rect === null || !$message->isIn($rect)) {
                    continue;
                }

                // Wheel input moves focus only when scrolling succeeds; host-driven components cannot be checked here.
                if ($message->isWheel() && $component->writesBack() && !isset($updates[$component->id->value])) {
                    continue;
                }

                $landedOn = $component->id;
            }
        }

        if ($landedOn === null && $message->isClick()) {
            $landedOn = $current->containerEnteredBy($message, $visible, $holder);
        }

        [$next, $hookCommand] = $this->applyComponentUpdatesAndNotify($current, $updates, $outcome ?? $message);
        $answered = Cmd::batch($appResult->command, $childCommand, $hookCommand);

        return new UpdateResult(
            $next,
            Cmd::batch(
                $answered,
                PointerFocus::alreadyMoved($answered) ? null : PointerFocus::after($landedOn, $holder),
            ),
        );
    }

    /**
     * @return UpdateResult<static>
     */
    private function offeredToTheMountUnder(MouseMsg $message, Rect $screenRect): UpdateResult
    {
        $geometry = $this->geometry($screenRect);
        $under = null;

        foreach ($this->componentRegistry()->listeningMounts() as $mount) {
            $rect = $geometry->visibleRectOf($mount);
            if ($rect !== null && $message->isIn($rect)) {
                $under = $mount;
            }
        }

        return $under === null ? new UpdateResult($this) : $this->answeredBy($under, $message, $screenRect);
    }

    /**
     * Clicking a container's plain row focuses the container only when focus is outside it.
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

            if ($rect !== null && $message->isIn($rect) && $holdsStops && ($entered === null || $component->id->isUnder($entered))) {
                $entered = $component->id;
            }
        }

        return $entered === null || ($holder !== null && ($holder->equals($entered) || $holder->isUnder($entered)))
            ? null
            : $entered;
    }

    /**
     * Broadcast regardless of the hook result; hosts without components still need the hook.
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
        [$updates, $childCommand, $outcome] = $current->dispatchComponents(
            $components,
            $rects,
            $message,
            $current->childDispatcher(),
        );

        [$next, $hookCommand] = $this->applyComponentUpdatesAndNotify($current, $updates, $outcome ?? $message);

        return new UpdateResult(
            $next,
            Cmd::batch($appResult->command, $childCommand, $hookCommand),
        );
    }

    /**
     * @param list<Component<Interactive>> $components
     * @param array<string, Rect>          $rects
     *
     * @return array{array<string, array{Component<Interactive>, Interactive}>, ?Cmd, ?Message} the updates, their commands, and the outcome to hand on in place of the message
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
        $outcome = null;

        // Address each command to the component that produced it before combining commands.
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

            $answer = $widget instanceof Committing ? $widget->outcomeOf($addressed, $rect) : null;
            $outcome ??= $answer;
            $dispatch = $dispatcher->dispatchOne($widget, $addressed, $rect);

            if ($dispatch->changed || $answer !== null) {
                $updates[$key] = [$component, $dispatch->child];
            }

            $commands[] = $component->addressed($dispatch->command);
        }

        return [$updates, Cmd::batch(...$commands), $outcome];
    }

    /**
     * @return array<string, string>
     */
    private function hintsOfFocused(?ComponentId $focused): array
    {
        $appKeys = $this->declaredActions()->footerKeybindings();
        $takesText = $this->takesText($focused);
        $appHints = $this->appActionHints($takesText ? $appKeys->withoutText() : $appKeys);

        if ($focused === null) {
            return $appHints;
        }

        $widget = $this->componentRegistry()->get($focused)?->widget;

        // Stored widgets exclude routing bindings; add focus-cycle keys only to hints, never to widget handling.
        if ($widget instanceof KeybindingsAware) {
            $widget = $widget->withGlobalKeybindings($this->globalKeybindings()->plus($this->focusCycleKeybindings()));
        }

        $actionHints = [];
        foreach ($this->componentsWithActionsAround($focused) as $component) {
            $actions = $component->actions();
            $shown = $takesText ? $actions->withoutText() : $actions;
            $byDescription = [];
            foreach ($shown->actions() as $action) {
                $byDescription[$component->labelOf($action) ?? Actions::description($action)][] = $action;
            }
            $actionHints += $shown->hints($byDescription);
        }

        $layerHints = [];
        foreach ($this->componentsAround($focused) as $component) {
            $layer = $component->widget;
            if ($component->id->equals($focused) || !$layer instanceof InputLayer || !$layer->claimsInput() || !$layer instanceof PublishesHints) {
                continue;
            }
            if ($layer instanceof KeybindingsAware) {
                $layer = $layer->withGlobalKeybindings($this->globalKeybindings());
            }
            $layerHints += $layer->hints();
        }

        // `+` keeps the first label for a key, in the order keys are handled, and keeps a digit label a key.
        return $appHints + $actionHints + ($widget instanceof PublishesHints ? $widget->hints() : []) + $layerHints;
    }

    /**
     * @return array<string, string>
     */
    private function keyHelpHint(?ComponentId $focused): array
    {
        $bindings = $this->keyHelpKeybindings();
        $working = $this->takesText($focused) ? $bindings->withoutText() : $bindings;
        $patterns = $working->patternsOf(Actions::HELP);

        return $patterns === [] ? [] : [$patterns[0]->label() => 'keys'];
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
     * Include local and inherited bindings that the widget handles, using its hint labels.
     *
     * @param array<string, true>   $listed
     * @param array<string, string> $wording
     *
     * @return array<string, string>
     */
    private function keysOf(Widget $widget, Keybindings $globals, array &$listed, array $wording): array
    {
        if (!$widget instanceof KeybindingsAware) {
            return [];
        }

        $bound = $widget->withGlobalKeybindings($globals);
        $own = [
            ...$widget->withGlobalKeybindings(null)->effectiveKeybindings()->actions(),
            ...($bound instanceof BindsForTheMoment ? $bound->momentaryKeybindings()->actions() : []),
        ];

        return $this->described($bound->effectiveKeybindings()->restrictedTo(...$own), $listed, $wording);
    }

    /**
     * List each key once, in the closest group that handles it; use Actions::description() for actions without hint wording.
     *
     * @param array<string, true>   $listed  the key labels already listed, added to as this lists more
     * @param array<string, string> $wording key label => description, as hints are
     *
     * @return array<string, string> key label => what it does
     */
    private function described(Keybindings $bindings, array &$listed, array $wording = []): array
    {
        $described = [];

        foreach ($bindings->actions() as $action) {
            $leading = ($bindings->patternsOf($action)[0] ?? null)?->label();
            $description = $leading !== null && isset($wording[$leading]) ? $wording[$leading] : Actions::description($action);
            $labels = [];
            foreach ($bindings->patternsOf($action) as $pattern) {
                $label = $pattern->label();
                if (!isset($listed[$label])) {
                    $listed[$label] = true;
                    $labels[] = $label;
                }
            }

            if ($labels !== []) {
                $described[implode('/', $labels)] = $description;
            }
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

    /**
     * @return UpdateResult<static>|null
     */
    private function answeredByComponentAction(KeyMsg $message, ?ComponentId $focused): ?UpdateResult
    {
        foreach ($this->componentsWithActionsAround($focused) as $component) {
            $action = $component->actions()->actionOf($message);
            if ($action === null || !$component->writesBack() || !$component->widget instanceof Interactive) {
                continue;
            }

            $answered = $component->replaceIn($this, $component->widget, new ActionMsg($action, $message));

            return new UpdateResult($this->appModel($answered), $component->addressed($answered->command));
        }

        return null;
    }

    /**
     * @return list<Component<Widget>>
     */
    private function componentsWithActionsAround(?ComponentId $focused): array
    {
        return array_values(array_filter(
            $this->componentsAround($focused),
            static fn (Component $component): bool => $component->actions()->actions() !== [],
        ));
    }

    /**
     * The component holding the keyboard and each one around it, nearest first: the ones its id
     * is under (a grafted child), then the ones that draw it (a bound field inside a bound modal).
     *
     * @return list<Component<Widget>>
     */
    private function componentsAround(?ComponentId $focused): array
    {
        if ($focused === null) {
            return [];
        }

        $registry = $this->componentRegistry();
        $found = [];
        for ($id = $focused; $id !== null; $id = $id->parent()) {
            $component = $registry->get($id);
            if ($component !== null) {
                $found[$component->id->value] = $component;
            }
        }
        foreach (array_reverse($registry->enclosing($focused)) as $component) {
            $found[$component->id->value] ??= $component;
        }

        return array_values($found);
    }

    private function declaredActions(): AppActions
    {
        static $declared = null;
        $declared ??= new WeakMap();

        /**
         * @var WeakMap<object, AppActions> $declared
         */
        return $declared[$this] ??= $this->actions();
    }

    /**
     * @return array<string, string> key label => the action's label, or its description
     */
    private function appActionHints(Keybindings $shown): array
    {
        $declared = $this->declaredActions();
        $byLabel = [];
        foreach ($shown->actions() as $action) {
            $byLabel[$declared->labelOf($action) ?? Actions::description($action)][] = $action;
        }

        return $shown->hints($byLabel);
    }

    /**
     * @return UpdateResult<static>|null
     */
    private function answeredByAppAction(KeyMsg $message): ?UpdateResult
    {
        $declared = $this->declaredActions();
        $action = $declared->keybindings()->actionOf($message);
        $handler = $action === null ? null : $declared->handlerOf($action);
        if ($handler === null) {
            return null;
        }

        /** @var static|UpdateResult<static> $answered */
        $answered = $handler($this);
        $result = $this->resultOf($answered);

        return new UpdateResult($this->appModel($result), $result->command);
    }

    private function inputContext(Rect $screen, ?ComponentId $holder): InputContext
    {
        return new InputContext(
            $screen,
            $holder,
            fn (): array => $this->hintsOfFocused($holder) + $this->keyHelpHint($holder),
            fn (MouseMsg $message): ?ComponentId => $this->componentAt($message, $screen),
        );
    }

    private function componentAt(MouseMsg $message, Rect $screen): ?ComponentId
    {
        $under = null;
        foreach ($this->findComponentRects($screen, $this->componentRegistry()->all(), visible: true) as $id => $rect) {
            if ($message->isIn($rect)) {
                $under = $id;
            }
        }

        return $under === null ? null : ComponentId::named($under);
    }

    /**
     * @param static|UpdateResult<static> $answered
     *
     * @return UpdateResult<static>
     */
    private function resultOf(self|UpdateResult $answered): UpdateResult
    {
        return $answered instanceof UpdateResult ? $answered : new UpdateResult($answered);
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
     * @param Component<Interactive>                                       $answering
     * @param array<array-key, array{Component<Interactive>, Interactive}> $updates
     *
     * @return UpdateResult<static>
     */
    private function writtenBack(Component $answering, array $updates, ?Cmd $command, Message $message): UpdateResult
    {
        [$current, $hookCommand] = $this->applyComponentUpdatesAndNotify($this, $updates, $message);

        return new UpdateResult($current, Cmd::batch($answering->addressed($command), $hookCommand));
    }

    /**
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

        $result = $this->resultOf($updated->onComponentsChanged($ids));

        return [$updated->appModel($result), Cmd::batch($written, $result->command)];
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
            // Look up the current mounted model before each write, or a second update would undo the first.
            $current = $app->componentRegistry()->get($original->id) ?? $original;
            $replaced = $current->replaceIn($app, $widget, $message);
            ComponentWriteBack::verifyStored($current, $widget, $app, $replaced->model);
            /** @var static $app */
            $app = $replaced->model;
            $commands[] = $replaced->command;
        }

        ComponentWriteBack::verify($app->componentRegistry(), $updates);

        return [$app, Cmd::batch(...$commands)];
    }
}
