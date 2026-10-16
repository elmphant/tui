<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Rendering\Context\FloatTarget;
use Elmphant\Tui\Rendering\Context\FocusPath;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Context\RenderPass;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Testing\ScreenBuffer;
use Elmphant\Tui\Tests\Support\RootPass;
use Elmphant\Tui\Widget\Choice\Select;
use Elmphant\Tui\Widget\Popup\Dropdown;
use Elmphant\Tui\Widget\Popup\DropdownContent;
use Elmphant\Tui\Widget\Popup\PopupFrame;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DropdownFrameTest extends TestCase
{
    #[Test]
    public function aDropdownDrawsTheFrameItWasGiven(): void
    {
        $anchor = new Rect(0, 0, 30, 12);
        $dropdown = Dropdown::new(Select::new(['json', 'yaml']))
            ->withFrame(static fn (DropdownContent $content, FloatTarget $target): PopupFrame => new MarkedFrame($content, $target))
            ->update(new KeyMsg(' '), $anchor)->model;

        $frame = new Frame(30, 12);
        $pass = RenderPass::new(Theme::dark(), focus: FocusPath::nowhere());
        $pass->begin(0.0);
        $pass->context->withFocusHere(static function () use ($pass, $dropdown, $frame, $anchor): void {
            RootPass::draw($pass, $dropdown, $frame, $anchor);
        });
        $pass->floats->commit();

        $screen = ScreenBuffer::fromFrame($frame);
        $drawn = '';
        for ($row = 0; $row < 12; ++$row) {
            $drawn .= $screen->lineAt($row);
        }

        self::assertStringContainsString('#', $drawn, 'The supplied frame draws the chrome, not the built-in one');
    }
}

/**
 * A popup frame a caller could write themselves: it marks its top-left corner so
 * a test can tell it apart from the built-in one.
 */
final readonly class MarkedFrame implements PopupFrame
{
    public function __construct(
        private DropdownContent $content,
        private FloatTarget $target,
    ) {
    }

    public function content(): DropdownContent
    {
        return $this->content;
    }

    public function contentArea(Rect $area): Rect
    {
        return new Rect($area->x + 1, $area->y + 1, max(0, $area->width - 1), max(0, $area->height - 1));
    }

    public function target(): FloatTarget
    {
        return $this->target;
    }

    public function captures(MouseAction $action): bool
    {
        return true;
    }

    public function placement(Rect $anchor, Rect $viewport): Rect
    {
        return new Rect($anchor->x, min($anchor->y + 1, max(0, $viewport->bottom() - 4)), 10, 4);
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::flex());
    }

    /**
     * @return iterable<Widget>
     */
    public function children(): iterable
    {
        yield $this->content;
    }

    /**
     * @return list<array{0: Widget, 1: Rect}>
     */
    public function placedChildren(Rect $area): iterable
    {
        return [[$this->content, $this->contentArea($area)]];
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        $frame->putChar($area->x, $area->y, '#', 0, 0);

        foreach ($this->placedChildren($area) as [$child, $childArea]) {
            $child->render($frame, $childArea, $context);
        }
    }
}
