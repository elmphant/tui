<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Mount;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\Reducer;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Widget;
use Elmphant\Tui\Widget\WidgetContainer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MountAreaTest extends TestCase
{
    #[Test]
    public function aKeyForAMountLaysOutNothingInsideIt(): void
    {
        $tally = new LayoutTally();
        $harness = ModelHarness::for(new HostOfATallyMount(new TallyModel($tally)), 40, 6)->initialize();
        $before = $tally->placements;

        $harness->type('abcdefgh');

        self::assertSame(8, $harness->model()->counter->presses);
        self::assertSame($before, $tally->placements);
    }

    #[Test]
    public function aKeyReachesTheMountWithTheAreaItIsDrawnIn(): void
    {
        $harness = ModelHarness::for(new HostOfATallyMount(new TallyModel(new LayoutTally())), 40, 6)->initialize()->type('a');

        self::assertEquals(new Rect(10, 0, 30, 6), $harness->model()->counter->area);
    }
}

final class LayoutTally
{
    public int $placements = 0;
}

final readonly class TallyModel implements Reducer
{
    public function __construct(
        public LayoutTally $tally,
        public int $presses = 0,
        public ?Rect $area = null,
    ) {
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return $message instanceof KeyMsg
            ? new UpdateResult(new self($this->tally, $this->presses + 1, $area))
            : UpdateResult::ignored($this);
    }

    public function view(): Widget
    {
        return new TalliedContainer($this->tally, Text::new('presses: ' . $this->presses));
    }
}

final readonly class TalliedContainer implements Widget, WidgetContainer
{
    public function __construct(private LayoutTally $tally, private Widget $content)
    {
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::flex());
    }

    public function children(): array
    {
        return [$this->content];
    }

    public function placedChildren(Rect $area): array
    {
        ++$this->tally->placements;

        return [[$this->content, $area]];
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        $this->content->render($frame, $area, $context);
    }
}

final readonly class HostOfATallyMount extends App
{
    public function __construct(public TallyModel $counter)
    {
    }

    public function view(): Widget
    {
        return Layout::horizontal()
            ->add(Text::new('sidebar'), Constraint::exact(10))
            ->add(Mount::named('tally', $this->counter, $this->counter->view(), static fn (self $app, TallyModel $counter): self => new self($counter)), Constraint::flex());
    }
}
