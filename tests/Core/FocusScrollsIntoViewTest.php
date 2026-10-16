<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Core;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Structure\Panel;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FocusScrollsIntoViewTest extends TestCase
{
    /**
     * Tabbing to a field below the fold is useless if the reader cannot see it.
     */
    #[Test]
    public function aFieldTabbedToBelowTheFoldIsScrolledIntoView(): void
    {
        $harness = ModelHarness::for(TallFormApp::seeded(), 30, 6);

        foreach (range(1, 6) as $ignored) {
            $harness->dispatch(new KeyMsg(Key::Tab));
        }

        self::assertSame('form/6', $harness->focused()?->value);
        self::assertStringContainsString('f6', $harness->renderScreen()->plainText());
        self::assertNotNull($harness->renderFrame()->cursorPosition, 'the caret is on screen with the field');
    }
}

final readonly class TallFormApp extends App
{
    public function __construct(public Panel $form = new Panel('Form'))
    {
    }

    public static function seeded(): self
    {
        $panel = Panel::new('Form');

        foreach (range(0, 9) as $index) {
            $panel = $panel->add(TextInput::new()->withValue('f' . $index));
        }

        return new self($panel);
    }

    public function view(): Widget
    {
        return Component::bind('form', $this->form, static fn (self $app, Panel $pane): self => $app->cloneWith(form: $pane));
    }
}
