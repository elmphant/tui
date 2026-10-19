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
 * Where a filter's keys go. While nothing binds Actions::SEARCH the field takes
 * them, as always; once something does, the content holds them until that key,
 * and Esc or Enter hands them back with the query kept.
 *
 * A widget with a filter field of its own uses it beside Cloneable and
 * KeybindingsAwareBehavior, and declares `private bool $searching = false`.
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
     * Esc and Enter hand the keys back while the field holds them, and Enter means
     * nothing to the content of a filter that does not bind it itself.
     */
    public function momentaryKeybindings(): Keybindings
    {
        return $this->isSearching() ? FilterBar::backKeybindings() : Keybindings::empty();
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

    private function fieldHoldsTheKeys(RenderContext $context): bool
    {
        return $context->keyboardTakesText() ?? $this->takesText();
    }
}
