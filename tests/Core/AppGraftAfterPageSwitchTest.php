<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Core;

use Elmphant\Tui\Core\App;
use Elmphant\Tui\Core\Component;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseButton;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Choice\Select;
use Elmphant\Tui\Widget\Popup\Dropdown;
use Elmphant\Tui\Widget\Prose\Paragraph;
use Elmphant\Tui\Widget\Structure\Panel;
use Elmphant\Tui\Widget\Structure\TabBar;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AppGraftAfterPageSwitchTest extends TestCase
{
    #[Test]
    public function aClickOnAnotherTabWhileADropdownIsOpenSwitchesTabs(): void
    {
        $harness = ModelHarness::for(new PageSwitchingApp(TabBar::new()
            ->addTab('Form')->add(Panel::new('Form')->add(Dropdown::new(Select::new(['a', 'b', 'c']))))
            ->addTab('About')->add(Paragraph::fromText('prose'))), 60, 12)->initialize();
        $harness->renderFrame();
        $harness->dispatch(new KeyMsg(Key::Tab));
        $harness->dispatch(new KeyMsg(Key::Enter));
        $harness->renderFrame();
        $about = mb_strpos($harness->renderScreen()->lineAt(1), 'About');
        self::assertNotFalse($about);

        $harness->dispatch(new MouseMsg($about, 1, MouseAction::Press, MouseButton::Left));

        self::assertStringContainsString('prose', $harness->renderScreen()->plainText());
    }
}

final readonly class PageSwitchingApp extends App
{
    public function __construct(public TabBar $tabs)
    {
    }

    public function view(): Widget
    {
        return Component::bind('tabs', $this->tabs, static fn (self $app, TabBar $tabs): self => $app->cloneWith(tabs: $tabs));
    }
}
