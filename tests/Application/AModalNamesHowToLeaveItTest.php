<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Bar\FocusHints;
use Elmphant\Tui\Widget\Bar\Footer;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Popup\Modal;
use Elmphant\Tui\Widget\Popup\Overlay;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AModalNamesHowToLeaveItTest extends TestCase
{
    #[Test]
    public function theFooterNamesTheModalsDismissBesideTheBoundFieldInsideIt(): void
    {
        $screen = ModelHarness::for(new ModalWithAFieldApp(TextInput::new()->withSubmit()), 80, 12)
            ->screen()
            ->plainText();

        self::assertStringContainsString('Enter submit', $screen, 'the field holds the keyboard');
        self::assertStringContainsString('Esc dismiss', $screen, 'and the modal around it says how to leave');
    }
}

final readonly class ModalWithAFieldApp extends App
{
    public function __construct(private TextInput $title)
    {
    }

    public function view(): Widget
    {
        $field = $this->bind('title');

        return Overlay::new(
            Layout::column(Text::new('Board'), Footer::new()->withLeft(FocusHints::new())),
            Component::bind('modal', Modal::new('New card', $field), static fn (self $app): self => $app),
        );
    }
}
