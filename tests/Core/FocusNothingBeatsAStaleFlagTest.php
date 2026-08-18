<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Core;

use Elmphant\Tui\Core\App;
use Elmphant\Tui\Core\Component;
use Elmphant\Tui\Core\FocusState;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Widget\TextInput;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A flag left on a widget is what the tree is replacing, so an app that put the
 * keyboard down must not have it handed back by one.
 */
final class FocusNothingBeatsAStaleFlagTest extends TestCase
{
    #[Test]
    public function aFlaggedWidgetGetsNoKeysWhileTheKeyboardIsDown(): void
    {
        $app = new StaleFlagApp(TextInput::new()->withFocus(true));

        $result = $app->update(new KeyMsg('x'), Rect::fromSize(40, 4), FocusState::nowhere());

        self::assertSame('', $result->model->field->value());
    }
}

final readonly class StaleFlagApp extends App
{
    public function __construct(
        public TextInput $field = new TextInput(),
    ) {
    }

    public function view(): Widget
    {
        return Component::bind(
            'field',
            $this->field,
            static fn (self $app, TextInput $field): self => $app->cloneWith(field: $field),
        );
    }
}
