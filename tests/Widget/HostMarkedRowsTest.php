<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\FocusState;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Context\RenderPass;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Focusable;
use Elmphant\Tui\Widget\Structure\FocusGutter;
use Elmphant\Tui\Widget\Structure\Panel;
use Elmphant\Tui\Widget\Widget;
use Elmphant\Tui\Widget\WidgetContainer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A host that marks its own rows has said where the keyboard is; a pane holding
 * the keyboard loosely must not light its first field over that.
 */
final class HostMarkedRowsTest extends TestCase
{
    #[Test]
    public function theFirstFieldStaysQuietWhenTheHostMarksAnotherRow(): void
    {
        $frame = new Frame(30, 6);
        $pass = RenderPass::new(Theme::dark());
        $pass->begin(0.0);
        $pass->focus->focusOn(new FocusState(ComponentId::named('pane')));

        (new MarkedRowsApp())->render($frame, $pass);

        self::assertSame(Theme::dark()->dim->foregroundValue(), $frame->foregrounds[1 * 30 + 3], 'the unmarked first field is drawn idle');
    }
}

final readonly class MarkedRowsApp extends App
{
    public function view(): Widget
    {
        return Component::named('pane', new MarkedRowsPane());
    }
}

/**
 * One stop that draws a Panel of marked rows inside itself rather than publishing them.
 */
final readonly class MarkedRowsPane implements Focusable, WidgetContainer
{
    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::flex());
    }

    /**
     * @return list<Widget>
     */
    public function children(): iterable
    {
        return $this->rows();
    }

    /**
     * @return list<array{Widget, Rect}>
     */
    public function placedChildren(Rect $area): iterable
    {
        return array_map(static fn (Widget $row): array => [$row, $area], $this->rows());
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return new UpdateResult($this);
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        Panel::new('Pane')->withChildren($this->rows())->render($frame, $area, $context);
    }

    /**
     * @return list<Widget>
     */
    private function rows(): array
    {
        return [
            FocusGutter::beside(TextInput::new()->withValue('first'), false),
            FocusGutter::beside(TextInput::new()->withValue('second'), true),
        ];
    }
}
