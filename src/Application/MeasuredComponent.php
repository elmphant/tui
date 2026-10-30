<?php

declare(strict_types=1);

namespace Elmphant\Tui\Application;

use Elmphant\Tui\Widget\MeasureHeight;
use Elmphant\Tui\Widget\Widget;

/**
 * Preserve the wrapped widget's measurement support when binding it.
 *
 * @internal Component::bind() answers it; a host names Component
 *
 * @template-covariant TWidget of Widget
 *
 * @extends Component<TWidget>
 */
final readonly class MeasuredComponent extends Component implements MeasureHeight
{
    public function measureHeight(int $width): int
    {
        assert($this->widget instanceof MeasureHeight);

        return $this->widget->measureHeight($width);
    }
}
