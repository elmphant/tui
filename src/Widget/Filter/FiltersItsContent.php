<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Filter;

use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Message\PasteMsg;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Line;
use Elmphant\Tui\Rendering\Span;
use Elmphant\Tui\Rendering\StyleToken;
use Elmphant\Tui\Search\Matcher;
use Elmphant\Tui\Widget\Dispatch\ChildDispatcher;
use Elmphant\Tui\Widget\Field\InputDecoration;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Prose\Paragraph;

/**
 * The field, the keys and the matches of a filter over a list or a table.
 * Use with SearchMode and DrawsFocus, and declare `$field`, `$matcher`, `$matching`, `$noMatchText` and `$noDataText`.
 *
 * @internal
 */
trait FiltersItsContent
{
    private const CONTENT_ACTIONS = [
        Actions::CURSOR_UP,
        Actions::CURSOR_DOWN,
        Actions::PAGE_UP,
        Actions::PAGE_DOWN,
        Actions::HOME,
        Actions::END,
        Actions::SELECT,
    ];

    /**
     * Keep Space and Home/End for text editing; expose top and bottom actions for global bindings such as `gg` and `G`.
     */
    public static function defaultKeybindings(): Keybindings
    {
        return (new Keybindings([
            Actions::CURSOR_UP => ['up'],
            Actions::CURSOR_DOWN => ['down'],
            Actions::PAGE_UP => ['shift+up', 'page_up'],
            Actions::PAGE_DOWN => ['shift+down', 'page_down'],
            Actions::HOME => [],
            Actions::END => [],
            Actions::SELECT => ['enter'],
        ]))->overriddenBy(FilterBar::keybindings())
            ->plus(TextInput::defaultKeybindings());
    }

    public function query(): string
    {
        return $this->field->value();
    }

    public function withQuery(string $query): self
    {
        return $query === $this->query() ? $this : $this->cloneWith(field: $this->field->withValue($query))->reFiltered();
    }

    public function withMatcher(Matcher $matcher): self
    {
        return $this->cloneWith(matcher: $matcher)->reFiltered();
    }

    public function withPlaceholder(string $placeholder): self
    {
        return $this->cloneWith(field: $this->field->withPlaceholder($placeholder));
    }

    /**
     * Shown when nothing matches the query.
     */
    public function withNoMatchText(string $noMatchText): self
    {
        return $this->cloneWith(noMatchText: $noMatchText);
    }

    /**
     * Shown when there is nothing to filter.
     */
    public function withNoDataText(string $noDataText): self
    {
        return $this->cloneWith(noDataText: $noDataText);
    }

    public function cursorSourceIndex(): ?int
    {
        return $this->matchingIndexes()[$this->contentCursor()] ?? null;
    }

    public function hints(): array
    {
        return $this->searchHints([
            'move' => [Actions::CURSOR_UP, Actions::CURSOR_DOWN],
            'choose' => Actions::SELECT,
            ...$this->clearFilterHint($this->query()),
        ]);
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        if ($message instanceof MouseMsg) {
            return $this->withMouse($message, $area);
        }

        if ($message instanceof PasteMsg) {
            return $this->typedIntoTheField($message, $area);
        }

        if (!$message instanceof KeyMsg) {
            return new UpdateResult($this);
        }

        $toggled = $this->searchToggledBy($message);
        if ($toggled !== null) {
            return new UpdateResult($toggled);
        }

        if ($this->effectiveKeybindings()->matches($message, Actions::CANCEL)) {
            return new UpdateResult($this->withQuery(''));
        }

        if ($this->belongsToTheContent($message)) {
            return $this->contentAnswered($message, $area);
        }

        return $this->takesText() ? $this->typedIntoTheField($message, $area) : new UpdateResult($this);
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        if ($area->width < 1 || $area->height < 1) {
            return;
        }

        $field = fn () => FilterBar::render($frame, FilterBar::barArea($area), $context, $this->fieldDrawnBy($context), $this->barSummary());
        $this->fieldHoldsTheKeys($context) ? $field() : $context->drawUnfocused($field);

        $body = $this->contentArea($area);
        if ($body->height < 1) {
            return;
        }

        if ($this->matchingIndexes() === []) {
            Paragraph::fromLines([new Line([Span::token($this->nothingToShow(), StyleToken::Dim)])])->render($frame, $body, $context);

            return;
        }

        $this->renderContent($frame, $body, $context);
    }

    abstract private function reFiltered(): self;

    abstract private function atTheTop(): self;

    abstract private function contentCursor(): int;

    /**
     * @return UpdateResult<self>
     */
    abstract private function contentAnswered(KeyMsg $message, Rect $area): UpdateResult;

    /**
     * @return UpdateResult<self>
     */
    abstract private function withMouse(MouseMsg $message, Rect $area): UpdateResult;

    abstract private function barSummary(): ?string;

    abstract private function nothingToShow(): string;

    abstract private function renderContent(Frame $frame, Rect $body, RenderContext $context): void;

    /**
     * @return UpdateResult<static>
     */
    private function typedIntoTheField(KeyMsg|PasteMsg $message, Rect $area): UpdateResult
    {
        $result = (new ChildDispatcher($this->globalKeybindings))->dispatchOne($this->field, $message, FilterBar::barArea($area));
        $typed = $this->cloneWith(field: $result->child);

        return new UpdateResult($result->child->value() === $this->query() ? $typed : $typed->reFiltered()->atTheTop(), $result->command);
    }

    private function fieldDrawnBy(RenderContext $context): TextInput
    {
        return $this->field->withDecoration(
            $this->drawsFocused($context) ? InputDecoration::Underline : InputDecoration::None,
        );
    }

    private function belongsToTheContent(KeyMsg $message): bool
    {
        $bindings = $this->effectiveKeybindings();

        foreach (self::CONTENT_ACTIONS as $action) {
            if ($bindings->matches($message, $action)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<int>
     */
    private function matchingIndexes(): array
    {
        return $this->matching->indexes;
    }

    private function contentArea(Rect $area): Rect
    {
        return FilterBar::bodyArea($area, FilterBar::GAP_BELOW);
    }
}
