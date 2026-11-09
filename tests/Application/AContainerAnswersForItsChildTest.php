<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Message\Pressed;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Field\Button;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Focus\AnswersForItsChildren;
use Elmphant\Tui\Widget\Focus\ChildAnswer;
use Elmphant\Tui\Widget\Interactive;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AContainerAnswersForItsChildTest extends TestCase
{
    #[Test]
    public function anAnswerTheContainerWithholdsNeverReachesTheHandlersBoundToIt(): void
    {
        $harness = ModelHarness::for(AskingTwiceApp::new(), width: 30, height: 4)->init();

        $harness->press('tab', 'enter');

        self::assertSame(0, $harness->model()->sent);
        self::assertTrue($harness->model()->asker->hasAsked(), 'the container stored what it made of the press');
        self::assertSame('asker/0', $harness->focused()?->value, 'and asked for the keyboard to go back to the field');
    }

    #[Test]
    public function anAnswerTheContainerPassesOnReachesTheHandlers(): void
    {
        $harness = ModelHarness::for(AskingTwiceApp::new(), width: 30, height: 4)->init();

        $harness->press('tab', 'enter', 'tab', 'enter');

        self::assertSame(1, $harness->model()->sent);
    }
}

final readonly class AsksTwice implements Interactive, AnswersForItsChildren
{
    public function __construct(private TextInput $field, private Button $send, private bool $asked = false)
    {
    }

    public function hasAsked(): bool
    {
        return $this->asked;
    }

    public function answeredFor(int $child, Message $answered): ChildAnswer
    {
        if (!$answered instanceof Pressed || $this->asked) {
            return new ChildAnswer($this, $answered);
        }

        return new ChildAnswer(new self($this->field, $this->send, true), null, Cmd::focusChild(0));
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::exact(4));
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return new UpdateResult($this);
    }

    public function children(): iterable
    {
        return [$this->field, $this->send];
    }

    public function placedChildren(Rect $area): iterable
    {
        return [[$this->field, new Rect($area->x, $area->y, $area->width, 1)], [$this->send, new Rect($area->x, $area->y + 1, $area->width, 3)]];
    }

    public function withChildAt(int $index, Widget $child): static
    {
        return match (true) {
            $index === 0 && $child instanceof TextInput => new static($child, $this->send, $this->asked),
            $index === 1 && $child instanceof Button => new static($this->field, $child, $this->asked),
            default => $this,
        };
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        foreach ($this->placedChildren($area) as $index => [$child, $rect]) {
            $context->drawChild($index, $child, $frame, $rect);
        }
    }
}

final readonly class AskingTwiceApp extends App
{
    public function __construct(public AsksTwice $asker, public int $sent = 0)
    {
    }

    public static function new(): self
    {
        return new self(new AsksTwice(TextInput::new(), Button::new('Send')));
    }

    public function view(): Widget
    {
        return $this->bind('asker')->on(Pressed::class, static fn (self $app): self => $app->cloneWith(sent: $app->sent + 1));
    }
}
