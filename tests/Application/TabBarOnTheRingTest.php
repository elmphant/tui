<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseButton;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Structure\TabBar;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TabBarOnTheRingTest extends TestCase
{
    /**
     * The keyboard starts in the first field of the page rather than on the strip,
     * so one walk reaches the second one.
     */
    #[Test]
    public function aCharacterLandsInTheFieldTheReaderTabbedTo(): void
    {
        $harness = ModelHarness::for(new TabbedFormApp(), 60, 20);

        $harness->dispatch(new KeyMsg(Key::Tab));
        $harness->dispatch(new KeyMsg('x'));

        $model = $harness->model();
        self::assertInstanceOf(TabbedFormApp::class, $model);
        self::assertSame(['', 'x'], $model->fieldValues());
    }

    /**
     * The strip is a stop of its own, so a character arriving there belongs to the
     * strip — routing it on would type into a field nobody is looking at.
     */
    #[Test]
    public function aCharacterOnTheStripLandsInNoField(): void
    {
        $harness = ModelHarness::for(new TabbedFormApp(), 60, 20);

        $harness->press('shift+tab');
        $harness->dispatch(new KeyMsg('a'));

        $model = $harness->model();
        self::assertInstanceOf(TabbedFormApp::class, $model);
        self::assertSame(['', ''], $model->fieldValues());
    }

    #[Test]
    public function theStripHoldingTheKeyboardDrawsNoCaretInAField(): void
    {
        $harness = ModelHarness::for(new TabbedFormApp(), 60, 20);
        $harness->press('shift+tab');

        self::assertSame('bar', $harness->focused()?->value);
        self::assertNull($harness->renderFrame()->cursorPosition, 'no field holds the keyboard, so none draws a caret');
    }

    #[Test]
    public function clickingATabSwitchesToIt(): void
    {
        $harness = ModelHarness::for(TabbedFormApp::withTwoPages(), 60, 20);

        $harness->dispatch(new MouseMsg(12, 1, MouseAction::Press, MouseButton::Left));

        $model = $harness->model();
        self::assertInstanceOf(TabbedFormApp::class, $model);
        self::assertSame(1, $model->bar()->selectedTab());
    }

    #[Test]
    public function theStripAnswersTheArrowsOnceTheReaderClicksIt(): void
    {
        $harness = ModelHarness::for(TabbedFormApp::withTwoPages(), 60, 20);

        $harness->dispatch(new MouseMsg(2, 1, MouseAction::Press, MouseButton::Left));
        $harness->dispatch(new KeyMsg(Key::Right));

        $model = $harness->model();
        self::assertInstanceOf(TabbedFormApp::class, $model);
        self::assertSame(1, $model->bar()->selectedTab(), 'The strip holds the keyboard, so the arrow walks the tabs');
    }
}

final readonly class TabbedFormApp extends App
{
    public function __construct(private ?TabBar $bar = null)
    {
    }

    public static function withTwoPages(): self
    {
        return new self(
            TabBar::new()
                ->addTab('Alpha')->add(TextInput::new())
                ->addTab('Beta')->add(TextInput::new()),
        );
    }

    public function bar(): TabBar
    {
        return $this->bar ?? TabBar::new()->addTab('Form')->add(TextInput::new())->add(TextInput::new());
    }

    /**
     * @return list<string>
     */
    public function fieldValues(): array
    {
        return array_map(
            static fn (Widget $field): string => $field instanceof TextInput ? $field->value() : '?',
            $this->bar()->children(),
        );
    }

    public function view(): Widget
    {
        return Component::bind('bar', $this->bar(), static fn (self $app, TabBar $bar): self => $app->cloneWith(bar: $bar));
    }
}
