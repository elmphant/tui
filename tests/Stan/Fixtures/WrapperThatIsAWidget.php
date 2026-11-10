<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Stan\Fixtures;

use Elmphant\Tui\Widget\DecoratesOneChild;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Widget;
use Elmphant\Tui\Widget\WidgetContainer;

final readonly class WrapperThatIsAWidget implements Widget, WidgetContainer
{
    use DecoratesOneChild;

    protected function wrapped(): Widget
    {
        return Text::new('inside');
    }
}
