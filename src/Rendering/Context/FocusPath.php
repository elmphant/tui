<?php

declare(strict_types=1);

namespace Elmphant\Tui\Rendering\Context;

use Closure;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\FocusState;
use LogicException;

/**
 * Where the keyboard is while a tree is drawn, so each widget can ask instead of
 * remembering.
 */
final class FocusPath
{
    private FocusState $focus;
    private int $depth;

    /**
     * Segments of the focused id still to be matched, below the component being
     * drawn. Empty means the keyboard is on that component itself.
     *
     * @var list<string>
     */
    private array $path = [];

    /**
     * Components whose subtree holds the focused one without naming it in their id:
     * a bound child inside a pane.
     *
     * @var list<string>
     */
    private array $enclosure = [];

    /**
     * @var list<string>
     */
    private array $stopPublishers = [];

    private bool $loose = false;

    /**
     * @var array<string, string>
     */
    private array $hints = [];

    private function __construct(private readonly FocusState $initialFocus, private readonly int $initialDepth)
    {
        $this->focus = $initialFocus;
        $this->depth = $initialDepth;
    }

    /**
     * Focus not decided yet: fine for one widget on its own, refused for a tree of
     * components.
     */
    public static function undecided(): self
    {
        return new self(new FocusState(), 0);
    }

    public static function nowhere(): self
    {
        return new self(FocusState::nowhere(), 0);
    }

    /**
     * For one widget on its own; inside a tree Component opens the scope.
     */
    public static function here(): self
    {
        return new self(FocusState::nowhere(), 1);
    }

    public function begin(): void
    {
        $this->focus = $this->initialFocus;
        $this->depth = $this->initialDepth;
        $this->path = [];
        $this->loose = false;
        $this->enclosure = [];
        $this->stopPublishers = [];
        $this->hints = [];
    }

    public function focusOn(FocusState $focus): void
    {
        // A tree with nothing to focus leaves the runtime still waiting for an
        // answer. For this frame that means nobody: saying so is what the guard
        // in within() asks for, and the tree settles on a component once it has one.
        $this->focus = $focus->isUndecided() ? FocusState::nowhere() : $focus;
        $this->depth = 0;
        $this->path = [];
        $this->loose = false;
    }

    public function focusedComponent(): ?ComponentId
    {
        return $this->focus->focused();
    }

    /**
     * @param array<string, string> $hints
     */
    public function provideHints(array $hints): void
    {
        $this->hints = $hints;
    }

    /**
     * @param list<string> $ids
     */
    public function provideEnclosure(array $ids): void
    {
        $this->enclosure = $ids;
    }

    /**
     * @param list<string> $ids
     */
    public function provideStopPublishers(array $ids): void
    {
        $this->stopPublishers = $ids;
    }

    public function keyboardIsLoose(): bool
    {
        return $this->loose && $this->hasFocus();
    }

    /**
     * @return array<string, string>
     */
    public function hints(): array
    {
        return $this->hints;
    }

    public function hasFocus(): bool
    {
        return $this->depth > 0;
    }

    /**
     * @param Closure(): void $render
     */
    public function withFocusHere(Closure $render): void
    {
        ++$this->depth;

        try {
            $render();
        } finally {
            --$this->depth;
        }
    }

    /**
     * @param Closure(): void $render
     */
    public function withoutFocus(Closure $render): void
    {
        $held = $this->depth;
        $this->depth = 0;

        try {
            $render();
        } finally {
            $this->depth = $held;
        }
    }

    /**
     * @param Closure(): void $render
     */
    public function withinComponent(ComponentId $id, Closure $render): void
    {
        if ($this->focus->isUndecided()) {
            throw new LogicException(sprintf('Component "%s" is being drawn before anything said where the keyboard is. Call focusOn() first — FocusState::nowhere() if the answer is nobody.', $id->value));
        }

        $focused = $this->focus->focused()->value ?? '';
        $under = str_starts_with($focused, $id->value . '/');
        $holdsFocus = $focused === $id->value || $under || in_array($id->value, $this->enclosure, true);
        $path = $this->path;
        $loose = $this->loose;

        if ($holdsFocus) {
            ++$this->depth;
            $this->path = $under
                ? explode('/', substr($focused, strlen($id->value) + 1))
                : [];
            $this->loose = $focused === $id->value
                && !in_array($id->value, $this->stopPublishers, true);
        }

        try {
            $render();
        } finally {
            $this->path = $path;
            $this->loose = $loose;
            if ($holdsFocus) {
                --$this->depth;
            }
        }
    }

    /**
     * @param Closure(): void $render
     */
    public function withinChild(int $index, Closure $render): void
    {
        if ($this->focusedChildIndex() !== $index) {
            $this->withoutFocus($render);

            return;
        }

        $path = $this->path;
        array_shift($this->path);

        try {
            $render();
        } finally {
            $this->path = $path;
        }
    }

    public function focusedChildIndex(): ?int
    {
        $segment = $this->path[0] ?? null;

        return $this->hasFocus() && $segment !== null && ctype_digit($segment) ? (int)$segment : null;
    }
}
