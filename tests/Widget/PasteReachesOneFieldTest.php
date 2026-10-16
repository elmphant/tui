<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\FocusState;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\PasteMsg;
use Elmphant\Tui\Widget\Dispatch\ScrollPane;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PasteReachesOneFieldTest extends TestCase
{
    #[Test]
    public function aPasteLandsInTheFieldTheKeyboardIsOnAndNowhereElse(): void
    {
        $app = new PasteTargetApp(ScrollPane::of(TextInput::new(), TextInput::new()));

        $pasted = $app->update(
            new PasteMsg('hi'),
            Rect::fromSize(40, 10),
            new FocusState(ComponentId::named('pane/1')),
        )->model;

        self::assertInstanceOf(PasteTargetApp::class, $pasted);
        self::assertSame(['', 'hi'], $pasted->fieldValues());
    }
}

final readonly class PasteTargetApp extends App
{
    public function __construct(public ScrollPane $pane)
    {
    }

    /**
     * @return list<string>
     */
    public function fieldValues(): array
    {
        $values = [];

        foreach ($this->pane->authoredChildren() as $child) {
            if ($child instanceof TextInput) {
                $values[] = $child->value();
            }
        }

        return $values;
    }

    public function view(): Widget
    {
        return Component::bind('pane', $this->pane, static fn (self $app, ScrollPane $pane): self => $app->cloneWith(pane: $pane));
    }
}
