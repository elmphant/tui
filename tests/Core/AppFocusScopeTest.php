<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Core;

use ArrayObject;
use Elmphant\Tui\Core\App;
use Elmphant\Tui\Core\Component;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\FocusState;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Layout;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Widget\Focusable;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AppFocusScopeTest extends TestCase
{
    /**
     * Neither field carries a focus flag: only the placement decides.
     */
    #[Test]
    public function theRenderedTreeIsToldWhichComponentTheKeyboardIsIn(): void
    {
        /** @var ArrayObject<string, bool> $seen */
        $seen = new ArrayObject();

        $app = new ScopedFocusApp(
            new RecordingField('first', $seen),
            new RecordingField('second', $seen),
        );

        $context = new RenderContext(Theme::dark());
        $context->beginFrame(0.0);
        $context->focusOn(new FocusState(ComponentId::named('second')));
        $app->render(new Frame(20, 4), $context);

        self::assertSame(['first' => false, 'second' => true], $seen->getArrayCopy());
    }
}

final readonly class ScopedFocusApp extends App
{
    public function __construct(
        public RecordingField $first = new RecordingField('first', new ArrayObject()),
        public RecordingField $second = new RecordingField('second', new ArrayObject()),
    ) {
    }

    public function view(): Widget
    {
        return Layout::vertical(
            Component::bind('first', $this->first, static fn (self $app, RecordingField $w): self => $app->cloneWith(first: $w)),
            Component::bind('second', $this->second, static fn (self $app, RecordingField $w): self => $app->cloneWith(second: $w)),
        );
    }
}

final readonly class RecordingField implements Focusable
{
    /**
     * @param ArrayObject<string, bool> $seen
     */
    public function __construct(
        private string $name,
        private ArrayObject $seen,
        private bool $focused = false,
    ) {
    }

    public function isFocused(): bool
    {
        return $this->focused;
    }

    public function withFocus(bool $focused): static
    {
        return new self($this->name, $this->seen, $focused);
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::exact(1));
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return new UpdateResult($this);
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        $this->seen[$this->name] = $context->hasFocus();
    }
}
