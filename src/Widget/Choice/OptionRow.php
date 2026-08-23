<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Choice;

use Elmphant\Tui\Input\KeybindingsAware;
use Elmphant\Tui\Widget\Focusable;
use Elmphant\Tui\Widget\MeasureHeight;

/**
 * Options side by side on a row, wrapping onto further rows when the width runs
 * out: a checkbox row toggles each one, a radio row picks one of them.
 *
 * The shared surface is a type rather than a convention so a row that grows a
 * new way of answering cannot quietly keep the old one.
 */
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
     * Which option the keys act on. It rests where it is put; picking or
     * toggling is a separate move.
     */
    public function cursor(): int;

    public function withCursor(int $cursor): static;
}
