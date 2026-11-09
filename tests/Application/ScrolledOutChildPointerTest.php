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
use Elmphant\Tui\Widget\Choice\Radio;
use Elmphant\Tui\Widget\Prose\Paragraph;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Structure\Panel;
use Elmphant\Tui\Widget\Structure\TabBar;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ScrolledOutChildPointerTest extends TestCase
{
    #[Test]
    public function aClickOverAChildScrolledOutOfItsPaneLeavesTheChildAlone(): void
    {
        $harness = ModelHarness::for(LongFormApp::create(), 60, 12)->init();
        $harness->renderFrame();
        for ($press = 0; $press < 3; ++$press) {
            $harness->dispatch(new KeyMsg(Key::Tab));
        }
        for ($press = 0; $press < 4; ++$press) {
            $harness->dispatch(new KeyMsg(Key::PageDown));
        }
        $harness->renderFrame();
        $before = $harness->model()->tabs;
        $emptyColumnOfTheStrip = mb_strlen(rtrim($harness->screen()->lineAt(1))) - 20;

        $harness->dispatch(new MouseMsg($emptyColumnOfTheStrip, 1, MouseAction::Press, MouseButton::Left));

        self::assertEquals($before, $harness->model()->tabs);
    }
}

final readonly class LongFormApp extends App
{
    public function __construct(public TabBar $tabs)
    {
    }

    public static function create(): self
    {
        $form = Panel::new('Form');
        for ($field = 0; $field < 12; ++$field) {
            $form = $form->add(Text::new("Field $field"))->add(Radio::new(['alpha', 'beta', 'gamma', 'delta'], "radio $field"));
        }

        return new self(TabBar::new()->add('Form', $form)->add('About', Paragraph::fromText('prose')));
    }

    public function view(): Widget
    {
        return Component::bind('tabs', $this->tabs, static fn (self $app, TabBar $tabs): self => $app->cloneWith(tabs: $tabs));
    }
}
