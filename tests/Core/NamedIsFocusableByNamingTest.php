<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Core;

use Elmphant\Tui\Core\App;
use Elmphant\Tui\Core\Component;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NamedIsFocusableByNamingTest extends TestCase
{
    /**
     * Naming a pane is what says the keyboard may go there. A pane its host draws
     * and drives has no flag of its own to report, and needs none: the tree is
     * keeping that answer for it.
     */
    #[Test]
    public function aNamedPaneIsSomewhereTheKeyboardCanGo(): void
    {
        self::assertEquals(
            [ComponentId::named('pane')],
            (new NamedPlainPaneApp())->focusableIds(),
        );
    }
}

final readonly class NamedPlainPaneApp extends App
{
    public function view(): Widget
    {
        return Component::named('pane', new PlainPane());
    }
}
