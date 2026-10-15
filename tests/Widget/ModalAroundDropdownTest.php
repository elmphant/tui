<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Widget\Choice\Select;
use Elmphant\Tui\Widget\Popup\Dropdown;
use Elmphant\Tui\Widget\Popup\Modal;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ModalAroundDropdownTest extends TestCase
{
    #[Test]
    public function escapeShutsAnOpenDropdownInsideAndLeavesTheModalUp(): void
    {
        $area = Rect::fromSize(80, 24);
        $modal = Modal::new('Format', Dropdown::new(Select::new(['json', 'yaml'])));
        $modal = $modal->update(new KeyMsg(' '), $area)->model;

        $result = $modal->update(new KeyMsg(Key::Escape), $area);

        self::assertNull($result->command);
        $dropdown = $result->model->children()[0];
        self::assertInstanceOf(Dropdown::class, $dropdown);
        self::assertFalse($dropdown->isOpen());
    }
}
