<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Testing;

use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Tests\Widget\MarkingLogApp;
use Elmphant\Tui\Widget\Feedback\Spinner;
use Elmphant\Tui\Widget\Structure\Panel;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HarnessTimeTest extends TestCase
{
    #[Test]
    public function aSpinnerTurnsAsTheTestLetsTimePass(): void
    {
        $driven = ModelHarness::for(new MarkingLogApp(Panel::new('busy')->withChildren([Spinner::new()])), 20, 4)->initialize();

        $before = $driven->renderScreen()->lineAt(1);
        $after = $driven->advance(0.5)->renderScreen()->lineAt(1);

        self::assertNotSame($before, $after);
    }
}
