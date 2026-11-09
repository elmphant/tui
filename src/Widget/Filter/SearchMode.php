<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Filter;

use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\BindsForTheMoment;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Input\KeyPattern;
use Elmphant\Tui\Input\TakesText;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Rendering\Context\RenderContext;

/**
 * With Actions::SEARCH bound, content receives keys until search starts; Esc or Enter returns focus and keeps the query.
 * Use with Cloneable and KeybindingsAwareBehavior, declare `private bool $searching = false`, and answer query().
 *
 * @phpstan-require-implements TakesText
 * @phpstan-require-implements BindsForTheMoment
 */
trait SearchMode
{
    public function takesText(): bool
    {
        return $this->searching || !$this->searchIsBound();
    }

    public function isSearching(): bool
    {
        return $this->searching && $this->searchIsBound();
    }

    abstract public function query(): string;

    /**
     * While searching, Esc and Enter leave search. Otherwise the search action is live, and the keys that clear the
     * filter while there is a query.
     */
    public function momentaryKeybindings(): Keybindings
    {
        if ($this->isSearching()) {
            return FilterBar::backKeybindings();
        }

        $live = $this->searchIsBound() ? [Actions::SEARCH, ...$this->clearFilterActions()] : $this->clearFilterActions();

        return new Keybindings(array_fill_keys($live, []));
    }

    private function searchIsBound(): bool
    {
        return ($this->customKeybindings?->patternsOf(Actions::SEARCH) ?? []) !== []
            || ($this->globalKeybindings?->patternsOf(Actions::SEARCH) ?? []) !== [];
    }

    private function searchToggledBy(KeyMsg $message): ?self
    {
        $bindings = $this->effectiveKeybindings();

        if ($this->isSearching()) {
            return $bindings->matches($message, Actions::LEAVE_SEARCH)
                ? $this->cloneWith(searching: false)
                : null;
        }

        return $this->searchIsBound() && $bindings->matches($message, Actions::SEARCH)
            ? $this->cloneWith(searching: true)
            : null;
    }

    /**
     * @param array<string, string|list<string>> $onTheContent
     *
     * @return array<string, string>
     */
    private function searchHints(array $onTheContent): array
    {
        $bindings = $this->effectiveKeybindings();
        $back = $bindings->patternsOf(Actions::LEAVE_SEARCH);

        if ($this->isSearching() && $back !== []) {
            return [KeyPattern::joinedLabel(...$back) => Actions::description(Actions::LEAVE_SEARCH)];
        }

        return $bindings->hints($this->searchIsBound() ? [...$onTheContent, 'search' => Actions::SEARCH] : $onTheContent);
    }

    /**
     * @return array<string, list<string>>
     */
    private function clearFilterHint(): array
    {
        $actions = $this->clearFilterActions();

        return $actions === [] ? [] : ['clear filter' => $actions];
    }

    /**
     * @return list<string>
     */
    private function clearFilterActions(): array
    {
        return match (true) {
            $this->query() === '' => [],
            $this->searchIsBound() => [Actions::CANCEL],
            default => [Actions::CLEAR_LINE, Actions::CANCEL],
        };
    }

    private function fieldHoldsTheKeys(RenderContext $context): bool
    {
        return $context->keyboardTakesText() ?? $this->takesText();
    }
}
