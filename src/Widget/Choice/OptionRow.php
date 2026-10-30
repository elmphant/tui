<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Choice;

use Elmphant\Tui\Input\KeybindingsAware;
use Elmphant\Tui\Widget\Focusable;
use Elmphant\Tui\Widget\MeasureHeight;

interface OptionRow extends Focusable, KeybindingsAware, MeasureHeight
{
    /**
     * @return list<string>
     */
    public function options(): array;

    /**
     * @param list<string> $options
     */
    public function withOptions(array $options): static;

    /**
     * Move the cursor without changing the selection.
     */
    public function cursor(): int;

    public function withCursor(int $cursor): static;
}
