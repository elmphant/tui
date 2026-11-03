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
use Elmphant\Tui\Input\TakesText;
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
 * The dropdown owns the trigger row and popup; its content owns options, cursor and filtering.
 *
 * @template-covariant TContent of DropdownContent
 */
final readonly class Dropdown implements Focusable, HoldsAValue, KeybindingsAware, PublishesHints, CapturesKeys, TakesText
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
     * Customize the popup frame; the default is a rounded border.
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
     * @template T of DropdownContent
     *
     * @param T $content
     *
     * @return self<T>
     */
    public function withContent(DropdownContent $content): self
    {
        return new self(
            content: $content,
            open: $this->open,
            customKeybindings: $this->customKeybindings,
            globalKeybindings: $this->globalKeybindings,
            floatTarget: $this->floatTarget,
            frame: $this->frame,
        );
    }

    /**
     * @return string|bool|list<string>|null
     */
    public function value(): string|bool|array|null
    {
        return $this->content->value();
    }

    public function withValue(string|bool|array|null $value): static
    {
        return $this->cloneWith(content: $this->content->withValue($value));
    }

    public function caption(): string
    {
        return self::MARKER . $this->content->caption();
    }

    public function isOpen(): bool
    {
        return $this->open;
    }

    /**
     * Opening from the host also requires focus, because an unfocused dropdown does not draw its popup.
     */
    public function withOpen(bool $open = true): static
    {
        return $this->cloneWith(open: $open);
    }

    public function capturesKeys(): bool
    {
        return $this->open;
    }

    public function takesText(): bool
    {
        return $this->open && $this->content instanceof TakesText && $this->content->takesText();
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
                    ? $this->withOpen()
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

        // Reserve accent for selection; use cursor styling for focus.
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

        // Check tree focus: the stored open flag may outlive focus and would leave a popup that no longer receives keys.
        if ($this->open && $this->drawsFocused($context)) {
            $context->float($frame, $this->popup(), self::anchorIn($area));
        }
    }

    /**
     * Offer input to content first; a changed model or command consumes it before popup actions run.
     *
     * @return UpdateResult<static>
     */
    private function keyedWhileOpen(KeyMsg $message, Rect $area): UpdateResult
    {
        $commits = $this->content instanceof Committing && $this->content->commits($message);
        $answered = $this->content->update($message, $this->popupContentArea($area, $message->screen));
        $content = $answered->model;

        if ($commits) {
            return new UpdateResult($this->cloneWith(content: $content)->withOpen(false), $answered->command);
        }

        if ($content !== $this->content || $answered->command !== null) {
            return new UpdateResult($this->cloneWith(content: $content), $answered->command);
        }

        if ($this->effectiveKeybindings()->matches($message, Actions::CANCEL)) {
            return new UpdateResult($this->withOpen(false));
        }

        return new UpdateResult($this, $answered->command);
    }

    /**
     * Resolve clicks against the popup that was drawn, since its content may have changed since then.
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
     * Route captured non-selection events to content using its drawn area, as with any other widget.
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
            ? $this->cloneWith(content: $content)->withOpen(false)
            : $this->cloneWith(content: $content);
    }

    private function moused(MouseMsg $message, Rect $area): static
    {
        if (!$message->isClick()) {
            return $this;
        }

        if (!$message->isIn($area)) {
            return $this->open ? $this->withOpen(false) : $this;
        }

        return $this->open ? $this->withOpen(false) : $this->withOpen();
    }

    /**
     * Give content the popup's area, not the trigger row; pasted text needs only its width.
     */
    private function popupContentArea(Rect $area, ?Rect $screen): Rect
    {
        $popup = $this->popup();
        $anchor = self::anchorIn($area);

        return $popup->contentArea($screen === null ? $anchor : $popup->placement($anchor, $screen));
    }

    private static function anchorIn(Rect $area): Rect
    {
        return new Rect($area->x, $area->y, $area->width, 1);
    }

    private function popup(): PopupFrame
    {
        return $this->frame === null
            ? DropdownPopup::of($this->content, $this->floatTarget)
            : ($this->frame)($this->content, $this->floatTarget);
    }
}
