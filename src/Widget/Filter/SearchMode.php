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
 * Use with Cloneable and KeybindingsAwareBehavior, and declare `private bool $searching = false`.
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

    /**
     * Bind Esc and Enter to leave search only while searching; otherwise bind the search action on the content.
     */
    public function momentaryKeybindings(): Keybindings
    {
        return match (true) {
            $this->isSearching() => FilterBar::backKeybindings(),
            $this->searchIsBound() => new Keybindings([Actions::SEARCH => []]),
            default => Keybindings::empty(),
        };
    }

    private function searchIsBound(): bool
    {
        return ($this->customKeybindings?->get(Actions::SEARCH) ?? []) !== []
            || ($this->globalKeybindings?->get(Actions::SEARCH) ?? []) !== [];
    }

    private function isSearching(): bool
    {
        return $this->searching && $this->searchIsBound();
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
        $back = $bindings->get(Actions::LEAVE_SEARCH);

        if ($this->isSearching() && $back !== []) {
            return [KeyPattern::joinedLabel(...$back) => Actions::description(Actions::LEAVE_SEARCH)];
        }

        return $bindings->hints($this->searchIsBound() ? [...$onTheContent, 'search' => Actions::SEARCH] : $onTheContent);
    }

    /**
     * @return array<string, string|list<string>>
     */
    private function clearFilterHint(string $query): array
    {
        return $query === '' ? [] : ['clear filter' => $this->searchIsBound() ? Actions::CANCEL : [Actions::CLEAR_LINE, Actions::CANCEL]];
    }

    private function fieldHoldsTheKeys(RenderContext $context): bool
    {
        return $context->keyboardTakesText() ?? $this->takesText();
    }
}
