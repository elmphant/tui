<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Popup;

use Closure;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Input\KeybindingsAware;
use Elmphant\Tui\Input\KeybindingsAwareBehavior;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Message\PasteMsg;
use Elmphant\Tui\Rendering\Context\FloatMouseMsg;
use Elmphant\Tui\Rendering\Context\FloatTarget;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Support\Cloneable;
use Elmphant\Tui\Text\CharWidth;
use Elmphant\Tui\Widget\Committing;
use Elmphant\Tui\Widget\Focus\CapturesKeys;
use Elmphant\Tui\Widget\Focus\DrawsFocus;
use Elmphant\Tui\Widget\Focusable;
use Elmphant\Tui\Widget\HoldsAValue;
use Elmphant\Tui\Widget\PublishesHints;

/**
 * One row reading as what it holds, and a float to change that from.
 *
 * The row and the float are all this is; the options, their cursor and any
 * filter over them belong to the content, which answers for itself.
 *
 * @template-covariant TContent of DropdownContent
 */
final readonly class Dropdown implements Focusable, HoldsAValue, KeybindingsAware, PublishesHints, CapturesKeys
{
    use Cloneable;
    use DrawsFocus;
    use KeybindingsAwareBehavior;

    private const MARKER = '▾ ';

    /**
     * @param TContent $content
     */
    private function __construct(
        private DropdownContent $content,
        private bool $open = false,
        private ?Keybindings $customKeybindings = null,
        private ?Keybindings $globalKeybindings = null,
        private FloatTarget $floatTarget = new FloatTarget(),
        private ?Closure $frame = null,
    ) {
    }

    /**
     * The chrome drawn around the open list. Without one the list gets a rounded
     * border; supply a factory to put something else around it.
     *
     * @param Closure(DropdownContent, FloatTarget):PopupFrame $frame
     *
     * @return self<TContent>
     */
    public function withFrame(Closure $frame): self
    {
        return $this->cloneWith(frame: $frame);
    }

    /**
     * @template T of DropdownContent
     *
     * @param T $content
     *
     * @return self<T>
     */
    public static function new(DropdownContent $content): self
    {
        return new self($content);
    }

    /**
     * @return TContent
     */
    public function content(): DropdownContent
    {
        return $this->content;
    }

    /**
     * @return string|bool|list<string>|null
     */
    public function value(): string|bool|array|null
    {
        return $this->content->value();
    }

    /**
     * What the closed row reads, marker and all, so a host laying a row out can
     * measure it without knowing how a dropdown draws itself.
     */
    public function caption(): string
    {
        return self::MARKER . $this->content->caption();
    }

    public function isOpen(): bool
    {
        return $this->open;
    }

    public function capturesKeys(): bool
    {
        return $this->open;
    }

    public function withGlobalKeybindings(?Keybindings $keybindings): static
    {
        return $this->cloneWith(
            globalKeybindings: $keybindings,
            content: $this->content instanceof KeybindingsAware
                ? $this->content->withGlobalKeybindings($keybindings)
                : $this->content,
        );
    }

    public function sizing(): Sizing
    {
        return Sizing::of(
            Constraint::flex()->withBasis(CharWidth::stringWidth(rtrim($this->caption()))),
            Constraint::exact(1),
        );
    }

    /**
     * Only the two actions about the popup itself. Moving, choosing and typing
     * are the content's, and reach it because the content is asked first.
     */
    public static function defaultKeybindings(): Keybindings
    {
        return new Keybindings([
            Actions::TOGGLE => ['enter', 'space'],
            Actions::CANCEL => ['esc'],
        ]);
    }

    public function hints(): array
    {
        return $this->effectiveKeybindings()->hints(
            $this->open
                ? ['close' => Actions::CANCEL]
                : ['open' => Actions::TOGGLE],
        );
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        if ($message instanceof FloatMouseMsg) {
            return new UpdateResult($this->floatMoused($message));
        }

        if ($message instanceof MouseMsg) {
            return new UpdateResult($this->moused($message, $area));
        }

        if ($message instanceof PasteMsg && $this->open) {
            $answered = $this->content->update($message, $this->popupContentArea($area, null));

            return new UpdateResult($this->cloneWith(content: $answered->model), $answered->command);
        }

        if (!$message instanceof KeyMsg) {
            return new UpdateResult($this);
        }

        if (!$this->open) {
            return new UpdateResult(
                $this->effectiveKeybindings()->matches($message, Actions::TOGGLE)
                    ? $this->opened()
                    : $this,
            );
        }

        return $this->keyedWhileOpen($message, $area);
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        if ($area->width < 1 || $area->height < 1) {
            return;
        }

        // Accent says a value was chosen, so focus is marked the way every other
        // row under the cursor is; the caption is what tells the two apart.
        $style = match (true) {
            $this->drawsFocused($context) => $context->theme->cursorStyle,
            $this->content->hasChoice() => $context->theme->base,
            default => $context->theme->dim,
        };

        $frame->putStyled(
            $area->x,
            $area->y,
            CharWidth::truncateToWidth($this->caption(), $area->width),
            $style,
        );

        // The list belongs to whatever holds the keyboard. A tree that moved the
        // keyboard on never told this widget, so its own flag can still say open;
        // drawn anyway, the list would hang over rows the reader has moved to and
        // no key of theirs would reach it to shut it.
        if ($this->open && $this->drawsFocused($context)) {
            $context->float($frame, $this->popup(), $area);
        }
    }

    private function opened(): static
    {
        return $this->cloneWith(open: true);
    }

    private function closed(): static
    {
        return $this->cloneWith(open: false);
    }

    /**
     * The content answers first; only a key it left alone is the popup's own.
     * Consumption is a changed model or any command, as App defines it.
     *
     * @return UpdateResult<static>
     */
    private function keyedWhileOpen(KeyMsg $message, Rect $area): UpdateResult
    {
        $answered = $this->content->update($message, $this->popupContentArea($area, $message->screen));
        $content = $answered->model;

        if ($content instanceof Committing && $content->commits($message)) {
            return new UpdateResult($this->cloneWith(content: $content)->closed(), $answered->command);
        }

        if ($content !== $this->content || $answered->command !== null) {
            return new UpdateResult($this->cloneWith(content: $content), $answered->command);
        }

        if ($this->effectiveKeybindings()->matches($message, Actions::CANCEL)) {
            return new UpdateResult($this->closed());
        }

        return new UpdateResult($this, $answered->command);
    }

    /**
     * The popup the drain drew answers, not the current state: the reader
     * picked the row they saw, and the content may have moved on since.
     */
    private function floatMoused(FloatMouseMsg $message): static
    {
        $popup = $message->popup();

        if (!$this->open || !$popup instanceof PopupFrame || $popup->target() !== $this->floatTarget) {
            return $this;
        }

        return $message->mouse->action === MouseAction::Press
            ? $this->pickedFromDrawn($message->mouse, $popup, $message->drawnIn())
            : $this->wheeledInDrawn($message->mouse, $popup, $message->drawnIn());
    }

    /**
     * Everything the popup captures that is not a pick is the content's own,
     * answered against the area it was drawn in. Nothing here tells the content
     * it is floating: a mouse message and an area is what any widget is handed.
     */
    private function wheeledInDrawn(MouseMsg $mouse, PopupFrame $popup, Rect $placement): static
    {
        return $this->cloneWith(
            content: $this->content->update($mouse, $popup->contentArea($placement))->model,
        );
    }

    private function pickedFromDrawn(MouseMsg $mouse, PopupFrame $popup, Rect $placement): static
    {
        if (!$mouse->isClick()) {
            return $this;
        }

        $option = $popup->content()->optionAt($mouse, $popup->contentArea($placement));

        if ($option === null || !$this->content->hasOption($option)) {
            return $this;
        }

        $content = $this->content->activated($option);

        return $content instanceof Committing
            ? $this->cloneWith(content: $content)->closed()
            : $this->cloneWith(content: $content);
    }

    private function moused(MouseMsg $message, Rect $area): static
    {
        if (!$message->isClick()) {
            return $this;
        }

        if (!$message->inRect($area)) {
            return $this->open ? $this->closed() : $this;
        }

        return $this->open ? $this->closed() : $this->opened();
    }

    /**
     * Where the content is drawn: the popup's place on the screen, not the
     * one-row anchor. A paste carries no screen, and the field it lands in
     * reads only the width.
     */
    private function popupContentArea(Rect $anchor, ?Rect $screen): Rect
    {
        $popup = $this->popup();

        return $popup->contentArea($screen === null ? $anchor : $popup->placement($anchor, $screen));
    }

    private function popup(): PopupFrame
    {
        return $this->frame === null
            ? DropdownPopup::of($this->content, $this->floatTarget)
            : ($this->frame)($this->content, $this->floatTarget);
    }
}
