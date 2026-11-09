<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Stan\Fixtures;

use Elmphant\Tui\Application\BindsProperties;
use Elmphant\Tui\Support\Cloneable;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Widget;

final readonly class BoundPropertiesModel
{
    use BindsProperties;
    use Cloneable;
    private const QUERY = 'query';

    public function __construct(
        private TextInput $query,
        private TextInput $forgotten,
    ) {
    }

    public function view(): Widget
    {
        return $this->bind(self::QUERY);
    }
}
