<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Choice;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\BindsForTheMoment;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Input\KeybindingsAware;
use Elmphant\Tui\Input\KeybindingsAwareBehavior;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Message\Submitted;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Support\Cloneable;
use Elmphant\Tui\Text\CharWidth;
use Elmphant\Tui\Widget\Committing;
use Elmphant\Tui\Widget\Focus\DrawsFocus;
use Elmphant\Tui\Widget\Focusable;
use Elmphant\Tui\Widget\HoldsAValue;
use Elmphant\Tui\Widget\PublishesHints;
use Elmphant\Tui\Widget\ValueItCannotHold;
use InvalidArgumentException;

final readonly class Toggle implements Focusable, HoldsAValue, KeybindingsAware, BindsForTheMoment, PublishesHints, Committing
{
    use Cloneable;
    use DrawsFocus;
    use KeybindingsAwareBehavior;

    public const PICK_ON = 'pick_on';
    public const PICK_OFF = 'pick_off';
    public const PICKED_MARK = '● ';
    public const UNPICKED_MARK = '○ ';
    public const SEPARATOR = ' / ';

    private function __construct(
        private string $on,
        private string $off,
        private bool $isOn = false,
        private bool $submits = false,
        private ?Message $submit = null,
        private ?Keybindings $customKeybindings = null,
        private ?Keybindings $globalKeybindings = null,
    ) {
        if (trim($on) === '' || trim($off) === '') {
            throw new InvalidArgumentException('A Toggle label must not be blank.');
        }
        if ($on === $off) {
            throw new InvalidArgumentException(\sprintf('Toggle labels must differ, "%s" is on both sides.', $on));
        }
    }

    public static function new(string $on = 'Yes', string $off = 'No'): self
    {
        return new self($on, $off);
    }

    public static function defaultKeybindings(): Keybindings
    {
        return new Keybindings([
            Actions::CURSOR_LEFT => ['left'],
            Actions::CURSOR_RIGHT => ['right'],
            Actions::TOGGLE => ['space'],
        ]);
    }

    public function momentaryKeybindings(): Keybindings
    {
        $on = $this->letterOf($this->on);
        $off = $this->letterOf($this->off);
        $letters = $on === $off ? [] : array_filter([self::PICK_ON => $on, self::PICK_OFF => $off]);

        return new Keybindings([
            ...$letters,
            ...$this->submits ? [Actions::SELECT => ['enter']] : [],
        ]);
    }

    public function withSubmit(?Message $message = null): self
    {
        return $this->cloneWith(submits: true, submit: $message);
    }

    public function outcomeOf(Message $message, Rect $area): ?Message
    {
        return $this->submits
            && $message instanceof KeyMsg
            && $this->effectiveKeybindings()->matches($message, Actions::SELECT)
            ? new Submitted($this->isOn ? $this->on : $this->off, $message)
            : null;
    }

    public function hints(): array
    {
        return $this->effectiveKeybindings()->hints([
            'choose' => [Actions::CURSOR_LEFT, Actions::CURSOR_RIGHT],
            $this->on => self::PICK_ON,
            $this->off => self::PICK_OFF,
            'flip' => Actions::TOGGLE,
            'submit' => Actions::SELECT,
        ]);
    }

    public function isOn(): bool
    {
        return $this->isOn;
    }

    public function withOn(bool $isOn): self
    {
        return $this->cloneWith(isOn: $isOn);
    }

    public function value(): bool
    {
        return $this->isOn;
    }

    public function withValue(string|bool|array|null $value): static
    {
        return \is_bool($value) || $value === null
            ? $this->cloneWith(isOn: $value ?? false)
            : throw ValueItCannotHold::given($this, $value);
    }

    /**
     * @return array{string, string}
     */
    public function labels(): array
    {
        return [$this->on, $this->off];
    }

    public function withLabels(string $on, string $off): self
    {
        return $this->cloneWith(on: $on, off: $off);
    }

    public function sizing(): Sizing
    {
        return Sizing::of(
            Constraint::flex()->withBasis(CharWidth::stringWidth(implode('', array_column($this->pieces(), 0)))),
            Constraint::exact(1),
        );
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        if ($message instanceof MouseMsg && $message->isClick() && $message->isIn($area)) {
            return new UpdateResult($this->clickedAt($message->x - $area->x));
        }
        if (!$message instanceof KeyMsg) {
            return new UpdateResult($this);
        }

        $bindings = $this->effectiveKeybindings();

        return match (true) {
            $bindings->matches($message, Actions::CURSOR_LEFT),
            $bindings->matches($message, self::PICK_ON) => new UpdateResult($this->withOn(true)),
            $bindings->matches($message, Actions::CURSOR_RIGHT),
            $bindings->matches($message, self::PICK_OFF) => new UpdateResult($this->withOn(false)),
            $bindings->matches($message, Actions::TOGGLE) => new UpdateResult($this->withOn(!$this->isOn)),
            $this->submits && $this->submit !== null
                && $bindings->matches($message, Actions::SELECT) => new UpdateResult($this, Cmd::message($this->submit)),
            default => new UpdateResult($this),
        };
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        if ($area->width <= 0 || $area->height < 1) {
            return;
        }

        $focused = $this->drawsFocused($context);
        $column = $area->x;
        foreach ($this->pieces() as [$text, $isPicked]) {
            $room = $area->right() - $column;
            if ($room <= 0) {
                return;
            }
            $style = OptionStyle::of($context->theme, $focused && $isPicked, $isPicked, $focused);
            $column += $frame->putStyled($column, $area->y, CharWidth::truncateToWidth($text, $room), $style);
        }
    }

    /**
     * @return list<string>
     */
    private function letterOf(string $label): array
    {
        $first = strtolower(ltrim($label)[0] ?? '');
        if ($first < 'a' || $first > 'z') {
            return [];
        }
        $key = new KeyMsg($first);
        $taken = $this->globalKeybindings !== null
            && ($this->globalKeybindings->actionOf($key) !== null || $this->globalKeybindings->continues($key));

        return $taken ? [] : [$first];
    }

    /**
     * @return list<array{string, bool}>
     */
    private function pieces(): array
    {
        return [
            [($this->isOn ? self::PICKED_MARK : self::UNPICKED_MARK) . $this->on, $this->isOn],
            [self::SEPARATOR, false],
            [($this->isOn ? self::UNPICKED_MARK : self::PICKED_MARK) . $this->off, !$this->isOn],
        ];
    }

    private function clickedAt(int $column): self
    {
        $onWidth = CharWidth::stringWidth(self::PICKED_MARK . $this->on);

        return match (true) {
            $column < $onWidth => $this->withOn(true),
            $column >= $onWidth + CharWidth::stringWidth(self::SEPARATOR) => $this->withOn(false),
            default => $this,
        };
    }
}
