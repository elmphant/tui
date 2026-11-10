<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Message\Dismissed;
use Elmphant\Tui\Message\Pressed;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Bar\Header;
use Elmphant\Tui\Widget\Field\Button;
use Elmphant\Tui\Widget\Layout\Flex;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Popup\Modal;
use Elmphant\Tui\Widget\Popup\Overlay;
use Elmphant\Tui\Widget\Popup\PositionedOverlay;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Structure\Box;
use Elmphant\Tui\Widget\Structure\Card;
use Elmphant\Tui\Widget\Structure\Container;
use Elmphant\Tui\Widget\Structure\Panel;
use Elmphant\Tui\Widget\Structure\Themed;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AButtonInALayoutInADialogTest extends TestCase
{
    #[Test]
    public function theButtonHoldsTheKeyboard(): void
    {
        $harness = ModelHarness::for(ConfirmingApp::asking(), 60, 14)->init();

        self::assertSame('dialog/0/1', $harness->focused()?->value);
        self::assertStringContainsString('┏', $harness->screen()->plainText());
        self::assertSame('press', $harness->focusedHints()['Enter'] ?? null);
    }

    #[Test]
    public function enterPressesTheButton(): void
    {
        $harness = ModelHarness::for(ConfirmingApp::asking(), 60, 14)->init();

        $harness->press('enter');

        self::assertSame('pressed', self::heardBy($harness));
    }

    #[Test]
    public function escapeClosesTheDialog(): void
    {
        $harness = ModelHarness::for(ConfirmingApp::asking(), 60, 14)->init();

        $harness->press('esc');

        self::assertSame('dismissed', self::heardBy($harness));
    }

    #[Test]
    public function tabWalksTheTwoButtonsOfARow(): void
    {
        $harness = ModelHarness::for(ConfirmingApp::askingWithTwoButtons(), 60, 14)->init();

        $harness->press('tab', 'enter');

        self::assertSame('dialog/0/1/1', $harness->focused()?->value);
    }

    #[Test]
    public function aButtonInAnyPassiveContainerHoldsTheKeyboard(): void
    {
        $containers = [
            'flex' => Flex::column()->add(Text::new('Delete?'))->add(Button::new('Delete')),
            'box' => Box::new('Confirm')->withChildren([Text::new('Delete?'), Button::new('Delete')]),
            'container' => Container::around(Button::new('Delete')),
            'card' => Card::new('Confirm')->withContent(Button::new('Delete')),
            'overlay' => Overlay::new(Text::new('Delete?'), Button::new('Delete')),
            'themed' => Themed::around(Button::new('Delete'), static fn (Theme $theme): Theme => $theme),
        ];

        foreach ($containers as $name => $content) {
            $harness = ModelHarness::for(new ConfirmingApp(Modal::new('Delete?', $content)), 60, 14)->init();

            self::assertNotNull($harness->focused(), "a button in a $name");
            self::assertStringContainsString('┏', $harness->screen()->plainText(), "a button in a $name");
            $harness->press('enter');
            self::assertSame('pressed', self::heardBy($harness), "a button in a $name");
        }
    }

    #[Test]
    public function aButtonInAPositionedOverlayInAPaneHoldsTheKeyboard(): void
    {
        $pane = Panel::new('Pane')->withChildren([PositionedOverlay::at(3, 3)->withContent(Button::new('Delete'))]);
        $harness = ModelHarness::for(new PaneApp($pane), 40, 10)->init();

        self::assertSame('pane/0/0', $harness->focused()?->value);
        $harness->press('enter');
        $model = $harness->model();
        self::assertInstanceOf(PaneApp::class, $model);
        self::assertSame('pressed', $model->heard);
    }

    #[Test]
    public function aDialogOfTextAloneClosesOnEscape(): void
    {
        $harness = ModelHarness::for(new ConfirmingApp(Modal::new('Note', Header::new('Saved.'))), 60, 14)->init();

        $harness->press('esc');

        self::assertSame('dismissed', self::heardBy($harness));
    }

    #[Test]
    public function aDialogWhoseButtonNothingCanReachStillClosesOnEscape(): void
    {
        $harness = ModelHarness::for(new ConfirmingApp(Modal::new('Note', Header::new('Saved.')->withRight(Button::new('OK')))), 60, 14)->init();

        $harness->press('esc');

        self::assertSame('dismissed', self::heardBy($harness));
    }

    /**
     * @param ModelHarness<ConfirmingApp> $harness
     */
    private static function heardBy(ModelHarness $harness): string
    {
        $model = $harness->model();
        self::assertInstanceOf(ConfirmingApp::class, $model);

        return $model->heard;
    }
}

final readonly class PaneApp extends App
{
    public function __construct(public Panel $pane, public string $heard = '')
    {
    }

    public function view(): Widget
    {
        return $this->bind('pane')->on(Pressed::class, static fn (self $app): self => new self($app->pane, 'pressed'));
    }
}

final readonly class ConfirmingApp extends App
{
    public function __construct(public ?Modal $dialog, public string $heard = '')
    {
    }

    public static function asking(): self
    {
        return new self(Modal::new('Delete?', Layout::column(Text::new('Delete notes.txt?'), Button::new('Delete'))));
    }

    public static function askingWithTwoButtons(): self
    {
        return new self(Modal::new('Delete?', Layout::column(Text::new('Delete notes.txt?'), Layout::row(Button::new('Delete'), Button::new('Keep')))));
    }

    public function view(): Widget
    {
        $page = Text::new("heard: {$this->heard}");

        return $this->dialog === null ? $page : Overlay::new($page, $this->bind('dialog')
            ->on(Pressed::class, static fn (self $app): self => new self($app->dialog, 'pressed'))
            ->on(Dismissed::class, static fn (self $app): self => new self(null, 'dismissed')));
    }
}
