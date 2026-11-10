<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Application\ComponentWriteBack;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\FocusState;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseButton;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Support\Cloneable;
use Elmphant\Tui\Widget\Field\Button;
use Elmphant\Tui\Widget\Focusable;
use Elmphant\Tui\Widget\Interactive;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Structure\Panel;
use Elmphant\Tui\Widget\Widget;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Two controls can update the same field; writing back an untouched sibling must not undo the clicked control's change.
 */
final class ComponentWriteBackTest extends TestCase
{
    #[Test]
    public function aPressOnOneComponentSurvivesTheOtherKeepingItsPlace(): void
    {
        $result = (new SharedTallyApp())->update(
            new MouseMsg(0, 1, MouseAction::Press, MouseButton::Left),
            Rect::fromSize(20, 2),
            FocusState::on(ComponentId::named('keeper')),
        );

        self::assertInstanceOf(SharedTallyApp::class, $result->model);
        self::assertSame(
            99,
            $result->model->tally,
            'the keeper was never pressed, so nothing of its should land on top of the reset',
        );
    }

    #[Test]
    public function aFoldCarriesAChildUpdateThroughALayoutIntoItsPane(): void
    {
        $folded = ComponentWriteBack::foldedIntoAncestors(self::paneAndChildUpdates([1, 0]));

        self::assertCount(1, $folded);
        $pane = $folded[0][1];
        self::assertInstanceOf(Panel::class, $pane);
        $layout = [...$pane->children()][1];
        self::assertInstanceOf(Layout::class, $layout);
        self::assertSame('Gone', self::labelOf([...$layout->children()][0]));
    }

    #[Test]
    public function aFoldThroughAChildThePaneDoesNotHoldFails(): void
    {
        $this->expectException(LogicException::class);

        ComponentWriteBack::foldedIntoAncestors(self::paneAndChildUpdates([5, 0]));
    }

    #[Test]
    public function aFoldThroughAWidgetWithoutChildrenFails(): void
    {
        $this->expectException(LogicException::class);

        ComponentWriteBack::foldedIntoAncestors(self::paneAndChildUpdates([0, 0]));
    }

    #[Test]
    public function aFoldIntoALastChildThePaneDoesNotHoldFails(): void
    {
        $this->expectException(LogicException::class);

        ComponentWriteBack::foldedIntoAncestors(self::paneAndChildUpdates([7]));
    }

    #[Test]
    public function aFoldIntoALastChildTheLayoutDoesNotHoldFails(): void
    {
        $this->expectException(LogicException::class);

        ComponentWriteBack::foldedIntoAncestors(self::paneAndChildUpdates([1, 5]));
    }

    /**
     * @param non-empty-list<int> $path
     *
     * @return list<array{Component<Interactive>, Interactive}>
     */
    private static function paneAndChildUpdates(array $path): array
    {
        $pane = Panel::new('pane')->withChildren([Text::new('note'), Layout::column(Button::new('Go'))]);
        $bound = Component::bind('pane', $pane, static fn (App $owner): App => $owner);

        return [
            [$bound, $pane->withScrollOffset(1)],
            [$bound->childThrough($path, Button::new('Go')), Button::new('Gone')],
        ];
    }

    private static function labelOf(Widget $button): string
    {
        self::assertInstanceOf(Button::class, $button);
        $frame = new Frame(12, 1);
        $button->render($frame, new Rect(0, 0, 12, 1), RenderContext::unfocused(Theme::dark()));

        return trim(implode('', $frame->characters), ' []');
    }
}

final readonly class SharedTallyApp extends App
{
    use Cloneable;

    public function __construct(public int $tally = 0)
    {
    }

    public function view(): Widget
    {
        return Layout::column(
            Component::bind(
                'keeper',
                TallyKeeper::of($this->tally)->withFocus(true),
                static fn (self $app, TallyKeeper $keeper): self => $app->cloneWith(tally: $keeper->tally()),
            ),
            Component::bind(
                'resetter',
                TallyResetter::of($this->tally),
                static fn (self $app, TallyResetter $resetter): self => $app->cloneWith(tally: $resetter->tally()),
            ),
        );
    }
}

final readonly class TallyKeeper implements Focusable
{
    use Cloneable;

    private function __construct(private int $tally, private bool $focused = false)
    {
    }

    public static function of(int $tally): self
    {
        return new self($tally);
    }

    public function tally(): int
    {
        return $this->tally;
    }

    public function isFocused(): bool
    {
        return $this->focused;
    }

    public function withFocus(bool $focused): static
    {
        return $this->cloneWith(focused: $focused);
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
    }
}

final readonly class TallyResetter implements Focusable
{
    use Cloneable;

    private function __construct(private int $tally, private bool $focused = false)
    {
    }

    public static function of(int $tally): self
    {
        return new self($tally);
    }

    public function tally(): int
    {
        return $this->tally;
    }

    public function isFocused(): bool
    {
        return $this->focused;
    }

    public function withFocus(bool $focused): static
    {
        return $this->cloneWith(focused: $focused);
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::exact(1));
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        if ($message instanceof MouseMsg && $message->isClick() && $message->isIn($area)) {
            return new UpdateResult($this->cloneWith(tally: 99));
        }

        return new UpdateResult($this);
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
    }
}
