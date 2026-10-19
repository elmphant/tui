<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Filter;

use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Rendering\Context\RenderContext;

/**
 * @internal where a filter's keys go. While nothing binds Actions::SEARCH the field
 * takes them, as always; once something does, the content holds them until that
 * key, and Esc or Enter hands them back with the query kept.
 */
trait SearchMode
{
    public function takesText(): bool
    {
        return $this->searching || !$this->searchIsBound();
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

    /**
     * The keys handed between the field and the content, or null for a key that moves neither way.
     */
    private function searchToggledBy(KeyMsg $message): ?self
    {
        $bindings = $this->effectiveKeybindings();

        if ($this->isSearching()) {
            return $bindings->matches($message, Actions::CANCEL) || $bindings->matches($message, Actions::SELECT)
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
        return $this->effectiveKeybindings()->hints(match (true) {
            $this->isSearching() => ['back' => [Actions::CANCEL, Actions::SELECT]],
            $this->searchIsBound() => [...$onTheContent, 'search' => Actions::SEARCH],
            default => $onTheContent,
        });
    }

    private function fieldHoldsTheKeys(RenderContext $context): bool
    {
        return $context->keyboardTakesText() ?? $this->takesText();
    }
}
