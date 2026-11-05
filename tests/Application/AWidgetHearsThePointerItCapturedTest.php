<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Layout\SplitState;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Testing\UserInput;
use Elmphant\Tui\Widget\Choice\Select;
use Elmphant\Tui\Widget\Field\Button;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Popup\Dropdown;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Structure\Container;
use Elmphant\Tui\Widget\Structure\Panel;
use Elmphant\Tui\Widget\Structure\Split;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AWidgetHearsThePointerItCapturedTest extends TestCase
{
    #[Test]
    public function aButtonLetsGoWhenTheReleaseIsElsewhere(): void
    {
        $harness = ModelHarness::for(new CaptureApp(), width: 40, height: 12);
        $button = $harness->screen()->findOrFail('Save');
        $elsewhere = $harness->screen()->findOrFail('elsewhere');

        $harness->drag($button['column'], $button['row'], $elsewhere['column'], $elsewhere['row']);

        self::assertFalse($harness->model()->button->isArmed());
    }

    #[Test]
    public function aPressElsewhereClearsTheTextMarkedInAPanel(): void
    {
        $harness = ModelHarness::for(new CaptureApp(), width: 40, height: 12);
        $text = $harness->screen()->findOrFail('marked words');
        $harness->drag($text['column'], $text['row'], $text['column'] + 5, $text['row']);
        self::assertNotNull($harness->model()->panel->marked());

        $harness->click('elsewhere');

        self::assertNull($harness->model()->panel->marked());
    }

    #[Test]
    public function aClickElsewhereClosesAnOpenDropdown(): void
    {
        $harness = ModelHarness::for(new CaptureApp(dropdown: Dropdown::new(Select::new(['one', 'two']))->withOpen()), width: 40, height: 12);

        $harness->click('elsewhere');

        self::assertFalse($harness->model()->dropdown->isOpen());
    }

    #[Test]
    public function aSplitStopsDraggingWhenTheReleaseIsOutsideIt(): void
    {
        $harness = ModelHarness::for(new CaptureApp(), width: 40, height: 12);
        $divider = $harness->screen()->find('│', within: new Rect(0, $harness->screen()->rowOf('left'), 40, 1)) ?? self::fail('no divider');
        $elsewhere = $harness->screen()->findOrFail('elsewhere');

        $harness->dispatch(UserInput::press($divider['column'], $divider['row']));
        self::assertNotNull($harness->model()->split->state()->dragging);

        $harness->dispatch(UserInput::drag($elsewhere['column'], $elsewhere['row']));
        $harness->dispatch(UserInput::release($elsewhere['column'], $elsewhere['row']));

        self::assertNull($harness->model()->split->state()->dragging);
    }
}

final readonly class CaptureApp extends App
{
    public Button $button;
    public Panel $panel;
    public Dropdown $dropdown;
    public Split $split;

    public function __construct(?Button $button = null, ?Panel $panel = null, ?Dropdown $dropdown = null, ?Split $split = null)
    {
        $this->button = $button ?? Button::new('Save');
        $this->panel = $panel ?? Panel::new('notes')->add(Text::new('marked words'));
        $this->dropdown = $dropdown ?? Dropdown::new(Select::new(['one', 'two']));
        $this->split = $split ?? Split::row(new SplitState([500, 500]), Text::new('left'), Text::new('right'));
    }

    public function view(): Widget
    {
        return Layout::column(
            $this->bind('button'),
            Container::around($this->bind('panel'))->withSizing(Sizing::of(Constraint::flex(), Constraint::exact(3))),
            $this->bind('dropdown'),
            Container::around($this->bind('split'))->withSizing(Sizing::of(Constraint::flex(), Constraint::exact(2))),
            Text::new('elsewhere'),
        );
    }
}
