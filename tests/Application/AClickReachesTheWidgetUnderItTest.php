<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Message\Chosen;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Committing;
use Elmphant\Tui\Widget\Focusable;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AClickReachesTheWidgetUnderItTest extends TestCase
{
    #[Test]
    public function onlyTheClickedWidgetAnswersAnOutcome(): void
    {
        $harness = ModelHarness::for(new TwoLabels(), width: 30, height: 2)->click('right');

        self::assertSame(['right'], $harness->model()->heard);
    }

    #[Test]
    public function theFocusedWidgetDoesNotHearAClickElsewhere(): void
    {
        $harness = ModelHarness::for(new TwoLabels(), width: 30, height: 2)->click('left');

        $harness->click('right');

        self::assertSame(['left'], $harness->model()->left->clicks);
    }
}

final readonly class ClickLabel implements Focusable, Committing
{
    /**
     * @param list<string> $clicks
     */
    public function __construct(public string $text, public array $clicks = [])
    {
    }

    public function outcomeOf(Message $message, Rect $area): ?Message
    {
        return $message instanceof MouseMsg && $message->isClick() ? new Chosen($this->text, $message) : null;
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return new UpdateResult($message instanceof MouseMsg && $message->isClick() ? new self($this->text, [...$this->clicks, $this->text]) : $this);
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::exact(10), Constraint::exact(1));
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        $frame->putStyled($area->x, $area->y, $this->text, $context->theme->base);
    }
}

final readonly class TwoLabels extends App
{
    /**
     * @param list<string> $heard
     */
    public function __construct(
        public ClickLabel $left = new ClickLabel('left'),
        public ClickLabel $right = new ClickLabel('right'),
        public array $heard = [],
    ) {
    }

    public function view(): Widget
    {
        return Layout::column(
            $this->bind('left')->on(Chosen::class, static fn (self $app): self => $app->cloneWith(heard: [...$app->heard, 'left'])),
            $this->bind('right')->on(Chosen::class, static fn (self $app): self => $app->cloneWith(heard: [...$app->heard, 'right'])),
        );
    }
}
