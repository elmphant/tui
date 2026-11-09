<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Application\Mount;
use Elmphant\Tui\Message\Dismissed;
use Elmphant\Tui\Support\Cloneable;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Field\Button;
use Elmphant\Tui\Widget\Popup\Modal;
use Elmphant\Tui\Widget\Popup\Overlay;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AModalInsideAMountTest extends TestCase
{
    #[Test]
    public function escapeOnAButtonInsideTheModalClosesIt(): void
    {
        $harness = ModelHarness::for(new HostOfAMountedDialog(), 40, 12)->init();
        self::assertSame('asking/ok', $harness->focused()?->value, 'the keyboard starts on the button inside the dialog');

        $harness->press('escape');

        self::assertNotNull($harness->screen()->find('closed'));
    }
}

final readonly class HostOfAMountedDialog extends App
{
    use Cloneable;

    public function __construct(
        public AskingModel $asking = new AskingModel(),
    ) {
    }

    public function view(): Widget
    {
        return Mount::named('asking', $this->asking, $this->asking->view(), static fn (self $host, AskingModel $asking): self => $host->cloneWith(asking: $asking));
    }
}

final readonly class AskingModel extends App
{
    use Cloneable;

    public function __construct(
        public bool $open = true,
    ) {
    }

    public function view(): Widget
    {
        $unchanged = static fn (self $model): self => $model;

        return $this->open
            ? Overlay::new(
                Text::new('behind'),
                Component::bind('dialog', Modal::new('Ask', Component::bind('ok', Button::new('OK'), $unchanged)), $unchanged)
                    ->on(Dismissed::class, static fn (self $model): self => $model->cloneWith(open: false)),
            )
            : Text::new('closed');
    }
}
